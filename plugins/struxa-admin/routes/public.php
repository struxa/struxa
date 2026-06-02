<?php

declare(strict_types=1);

use App\Access\MemberAccessPolicy;
use App\Flash;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Routing\RouteContext;
use Slim\Views\Twig;
use StruxaAdmin\CatalogBrowseService;
use StruxaAdmin\CatalogSettings;
use StruxaAdmin\CatalogSubmissionRepository;
use StruxaAdmin\CatalogSubmissionValidator;
use StruxaAdmin\GitHubRepoClient;
use StruxaAdmin\ScreenshotStorage;
use StruxaAdmin\SubmissionKind;

return static function (App $app, \App\Plugin\PluginBootContext $ctx): void {
    $twig = $ctx->twig();
    $pdo = $ctx->pdo();
    $root = $ctx->projectRoot();
    $pluginRoot = $ctx->pluginRoot();
    $view = static fn (array $extra = []): array => $ctx->viewData($extra);
    $ns = '@plugin_struxa_admin';

    $settings = new CatalogSettings($pdo, $root);
    $submissions = new CatalogSubmissionRepository($pdo);
    $github = new GitHubRepoClient($settings->githubToken());
    $validator = new CatalogSubmissionValidator($github, $submissions);
    $browse = new CatalogBrowseService($root, $submissions, $settings);
    $screenshots = new ScreenshotStorage($pluginRoot);

    $requireLoggedIn = $ctx->memberAccess()->middleware(
        $twig,
        static fn (): array => $ctx->viewData(),
        MemberAccessPolicy::loggedIn(),
        'Catalog submission',
    );

    $renderCatalog = static function (
        Response $response,
        string $template,
        string $title,
        array $extra
    ) use ($twig, $view, $ns, $browse): Response {
        $catalog = $browse->loadMergedCatalog();
        $payload = array_merge($view([
            'page_title' => $title,
            'catalog_ok' => $catalog['ok'],
            'catalog_error' => $catalog['ok'] ? null : $catalog['error'],
        ]), $extra);
        if ($catalog['ok']) {
            $payload['catalog_themes'] = $catalog['themes'];
            $payload['catalog_plugins'] = $catalog['plugins'];
        } else {
            $payload['catalog_themes'] = [];
            $payload['catalog_plugins'] = [];
        }

        return $twig->render($response, $ns . '/' . $template, $payload);
    };

    $app->get('/plugins', function (Request $request, Response $response) use ($renderCatalog): Response {
        return $renderCatalog($response, 'public/plugins.twig', 'Struxa plugins', ['catalog_nav' => 'plugins']);
    })->setName('public.struxa_catalog.plugins');

    $app->get('/themes', function (Request $request, Response $response) use ($renderCatalog): Response {
        return $renderCatalog($response, 'public/themes.twig', 'Struxa themes', ['catalog_nav' => 'themes']);
    })->setName('public.struxa_catalog.themes');

    $app->get('/plugins/submit', function (Request $request, Response $response) use ($twig, $view, $ns): Response {
        return $twig->render($response, $ns . '/public/submit.twig', $view([
            'page_title' => 'Submit a plugin',
            'submit_kind' => SubmissionKind::PLUGIN,
            'submit_action' => 'public.struxa_catalog.plugins.submit',
        ]));
    })->add($requireLoggedIn)->setName('public.struxa_catalog.plugins.submit_form');

    $app->post('/plugins/submit', function (Request $request, Response $response) use (
        $twig,
        $view,
        $ns,
        $validator,
        $submissions,
        $screenshots
    ): Response {
        return (static function (Request $request, Response $response) use (
            $twig,
            $view,
            $ns,
            $validator,
            $submissions,
            $screenshots
        ): Response {
            $parser = RouteContext::fromRequest($request)->getRouteParser();
            $back = $parser->urlFor('public.struxa_catalog.plugins.submit_form');
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $result = processSubmission($request, $body, SubmissionKind::PLUGIN, $validator, $submissions, $screenshots);
            if (!$result['ok']) {
                return $twig->render($response, $ns . '/public/submit.twig', $view([
                    'page_title' => 'Submit a plugin',
                    'submit_kind' => SubmissionKind::PLUGIN,
                    'submit_action' => 'public.struxa_catalog.plugins.submit',
                    'errors' => $result['errors'],
                    'old' => $result['old'],
                ]));
            }
            Flash::set('success', 'Thank you! Your plugin was submitted for review. We will email you when it is approved.');

            return $response->withHeader('Location', $parser->urlFor('public.struxa_catalog.plugins'))->withStatus(302);
        })($request, $response);
    })->add($requireLoggedIn)->setName('public.struxa_catalog.plugins.submit');

    $app->get('/themes/submit', function (Request $request, Response $response) use ($twig, $view, $ns): Response {
        return $twig->render($response, $ns . '/public/submit.twig', $view([
            'page_title' => 'Submit a theme',
            'submit_kind' => SubmissionKind::THEME,
            'submit_action' => 'public.struxa_catalog.themes.submit',
        ]));
    })->add($requireLoggedIn)->setName('public.struxa_catalog.themes.submit_form');

    $app->post('/themes/submit', function (Request $request, Response $response) use (
        $twig,
        $view,
        $ns,
        $validator,
        $submissions,
        $screenshots
    ): Response {
        $parser = RouteContext::fromRequest($request)->getRouteParser();
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $result = processSubmission($request, $body, SubmissionKind::THEME, $validator, $submissions, $screenshots);
        if (!$result['ok']) {
            return $twig->render($response, $ns . '/public/submit.twig', $view([
                'page_title' => 'Submit a theme',
                'submit_kind' => SubmissionKind::THEME,
                'submit_action' => 'public.struxa_catalog.themes.submit',
                'errors' => $result['errors'],
                'old' => $result['old'],
            ]));
        }
        Flash::set('success', 'Thank you! Your theme was submitted for review.');

        return $response->withHeader('Location', $parser->urlFor('public.struxa_catalog.themes'))->withStatus(302);
    })->add($requireLoggedIn)->setName('public.struxa_catalog.themes.submit');

    $app->get('/struxa-catalog/screenshot/{file}', function (Request $request, Response $response, array $args) use ($screenshots): Response {
        $file = basename((string) ($args['file'] ?? ''));
        if ($file === '' || !preg_match('/^[a-z0-9\-_.]+$/i', $file)) {
            return $response->withStatus(404);
        }
        $path = $screenshots->absolutePath('screenshots/' . $file);
        if ($path === null) {
            return $response->withStatus(404);
        }
        $mime = mime_content_type($path) ?: 'application/octet-stream';
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            return $response->withStatus(404);
        }
        $response = $response->withHeader('Content-Type', $mime)->withHeader('Cache-Control', 'public, max-age=86400');
        $response->getBody()->write((string) stream_get_contents($stream));
        fclose($stream);

        return $response;
    })->setName('public.struxa_catalog.screenshot');
};

/**
 * @param array<string, mixed> $body
 * @return array{ok: true}|array{ok: false, errors: list<string>, old: array<string, string>}
 */
function processSubmission(
    Request $request,
    array $body,
    string $kind,
    CatalogSubmissionValidator $validator,
    CatalogSubmissionRepository $submissions,
    ScreenshotStorage $screenshots,
): array {
    $old = [
        'git_repo_url' => trim((string) ($body['git_repo_url'] ?? '')),
        'git_branch' => trim((string) ($body['git_branch'] ?? 'main')),
        'submitter_name' => trim((string) ($body['submitter_name'] ?? '')),
        'submitter_email' => trim((string) ($body['submitter_email'] ?? '')),
    ];
    $errors = [];
    if ($old['submitter_name'] === '') {
        $errors[] = 'Your name is required.';
    }
    if ($old['submitter_email'] === '' || !filter_var($old['submitter_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'A valid contact email is required.';
    }
    if ($old['git_repo_url'] === '') {
        $errors[] = 'Git repository URL is required.';
    }
    $validated = $validator->validateSubmission($kind, $old['git_repo_url'], $old['git_branch']);
    if (!$validated['ok']) {
        return ['ok' => false, 'errors' => array_merge($errors, $validated['errors']), 'old' => $old];
    }
    if ($errors !== []) {
        return ['ok' => false, 'errors' => $errors, 'old' => $old];
    }

    $shotPath = null;
    /** @var array<string, mixed>|null $files */
    $files = $request->getUploadedFiles();
    if (is_array($files) && isset($files['screenshot'])) {
        $uploaded = $files['screenshot'];
        if ($uploaded->getError() === UPLOAD_ERR_OK) {
            $tmp = sys_get_temp_dir() . '/struxa-shot-' . bin2hex(random_bytes(6));
            $uploaded->moveTo($tmp);
            $stored = $screenshots->storeFromPath($tmp, $validated['slug'], $kind);
            @unlink($tmp);
            if (!$stored['ok']) {
                return ['ok' => false, 'errors' => [$stored['error']], 'old' => $old];
            }
            $shotPath = $stored['relative_path'] !== '' ? $stored['relative_path'] : null;
        }
    }

    $userId = null;
    /** @var array<string, mixed>|null $cmsUser */
    $cmsUser = $request->getAttribute('cms_user');
    if (is_array($cmsUser) && isset($cmsUser['id'])) {
        $userId = (int) $cmsUser['id'] > 0 ? (int) $cmsUser['id'] : null;
    }

    $submissions->insert(
        $kind,
        $validated['git_repo_url'],
        $validated['git_branch'],
        $validated['slug'],
        $validated['name'],
        $validated['version'],
        $validated['description'],
        $validated['author'],
        $validated['manifest'],
        $shotPath,
        $old['submitter_name'],
        $old['submitter_email'],
        $userId,
    );

    return ['ok' => true];
}
