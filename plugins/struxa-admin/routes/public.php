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
use StruxaAdmin\CatalogCommentRepository;
use StruxaAdmin\CatalogDownloadStatsRepository;
use StruxaAdmin\CatalogEngagementActor;
use StruxaAdmin\CatalogEntryEngagement;
use StruxaAdmin\CatalogMemberContext;
use StruxaAdmin\CatalogPackageDownload;
use StruxaAdmin\CatalogRatingRepository;
use StruxaAdmin\CatalogSettings;
use StruxaAdmin\CatalogSubmissionRepository;
use StruxaAdmin\CatalogSubmissionValidator;
use StruxaAdmin\CatalogSubmitterContext;
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
    $downloadStats = new CatalogDownloadStatsRepository($pdo);
    $ratings = new CatalogRatingRepository($pdo);
    $comments = new CatalogCommentRepository($pdo);
    $engagement = new CatalogEntryEngagement($downloadStats, $ratings, $comments);
    $packageDownload = new CatalogPackageDownload($settings, $downloadStats);
    $github = new GitHubRepoClient($settings->githubToken());
    $validator = new CatalogSubmissionValidator($github, $submissions);
    $browse = new CatalogBrowseService($root, $submissions, $settings);
    $screenshots = new ScreenshotStorage($pluginRoot);

    $memberView = static function (array $extra = []) use ($view, $pdo, $submissions, $downloadStats): array {
        $base = $view($extra);

        return array_merge($base, CatalogMemberContext::forView($pdo, $base, $submissions, $downloadStats));
    };

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
    ) use ($twig, $view, $ns, $browse, $engagement): Response {
        $catalog = $browse->loadMergedCatalog();
        $payload = array_merge($view([
            'page_title' => $title,
            'catalog_ok' => $catalog['ok'],
            'catalog_error' => $catalog['ok'] ? null : $catalog['error'],
        ]), $extra);
        if ($catalog['ok']) {
            $payload['catalog_themes'] = $engagement->enrichList(SubmissionKind::THEME, $catalog['themes']);
            $payload['catalog_plugins'] = $engagement->enrichList(SubmissionKind::PLUGIN, $catalog['plugins']);
        } else {
            $payload['catalog_themes'] = [];
            $payload['catalog_plugins'] = [];
        }

        return $twig->render($response, $ns . '/' . $template, $payload);
    };

    $catalogSlugPattern = '[a-z0-9]+(?:-[a-z0-9]+)*';

    $resolvePackage = static function (string $kind, string $slug) use ($browse, $engagement): ?array {
        $entry = $browse->findPackage($kind, $slug);
        if ($entry === null) {
            return null;
        }

        return $engagement->enrichOne($kind, $entry);
    };

    $renderPackageShow = static function (
        Response $response,
        string $kind,
        string $slug,
        array $viewData
    ) use (
        $twig,
        $view,
        $ns,
        $pdo,
        $browse,
        $engagement,
        $ratings,
        $comments,
        $resolvePackage
    ): Response {
        $catalog = $browse->loadMergedCatalog();
        if (!$catalog['ok']) {
            return $response->withStatus(503);
        }

        $package = $resolvePackage($kind, $slug);
        if ($package === null) {
            return $response->withStatus(404);
        }

        $actor = CatalogEngagementActor::fromView($pdo, $viewData);
        $userRating = null;
        if ($actor['ok']) {
            $userRating = $ratings->userRating($kind, $slug, $actor['cms_user_id']);
        }

        $isPlugin = $kind === SubmissionKind::PLUGIN;
        $payload = array_merge($view([
            'page_title' => (string) ($package['name'] ?? $slug),
            'catalog_nav' => $isPlugin ? 'plugins' : 'themes',
            'catalog_ok' => true,
            'catalog_error' => null,
            'catalog_package' => $package,
            'catalog_package_kind' => $kind,
            'catalog_comments' => CatalogEngagementActor::decorateComments(
                $pdo,
                $comments->listVisible($kind, $slug)
            ),
            'catalog_user_rating' => $userRating,
            'catalog_can_engage' => $actor['ok'],
            'catalog_list_url' => $isPlugin ? 'public.struxa_catalog.plugins' : 'public.struxa_catalog.themes',
            'catalog_rate_action' => $isPlugin
                ? 'public.struxa_catalog.plugin_rate'
                : 'public.struxa_catalog.theme_rate',
            'catalog_comment_action' => $isPlugin
                ? 'public.struxa_catalog.plugin_comment'
                : 'public.struxa_catalog.theme_comment',
        ]), [
            'catalog_themes' => $engagement->enrichList(SubmissionKind::THEME, $catalog['themes']),
            'catalog_plugins' => $engagement->enrichList(SubmissionKind::PLUGIN, $catalog['plugins']),
        ]);

        return $twig->render($response, $ns . '/public/package_show.twig', $payload);
    };

    $app->get('/plugins', function (Request $request, Response $response) use ($renderCatalog): Response {
        return $renderCatalog($response, 'public/plugins.twig', 'Struxa plugins', ['catalog_nav' => 'plugins']);
    })->setName('public.struxa_catalog.plugins');

    $app->get('/themes', function (Request $request, Response $response) use ($renderCatalog): Response {
        return $renderCatalog($response, 'public/themes.twig', 'Struxa themes', ['catalog_nav' => 'themes']);
    })->setName('public.struxa_catalog.themes');

    $postPackageRating = static function (
        Request $request,
        Response $response,
        array $args,
        string $kind
    ) use ($view, $pdo, $ratings, $resolvePackage): Response {
        $parser = RouteContext::fromRequest($request)->getRouteParser();
        $slug = (string) ($args['slug'] ?? '');
        $listRoute = $kind === SubmissionKind::PLUGIN
            ? 'public.struxa_catalog.plugin_show'
            : 'public.struxa_catalog.theme_show';

        if ($resolvePackage($kind, $slug) === null) {
            return $response->withStatus(404);
        }

        $actor = CatalogEngagementActor::fromView($pdo, $view());
        if (!$actor['ok']) {
            return $response->withStatus(403);
        }

        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $rating = (int) ($body['rating'] ?? 0);
        if ($rating < 1 || $rating > 5) {
            Flash::set('error', 'Please choose a rating from 1 to 5 stars.');

            return $response
                ->withHeader('Location', $parser->urlFor($listRoute, ['slug' => $slug]) . '#rate')
                ->withStatus(302);
        }

        $ratings->upsert($kind, $slug, $actor['cms_user_id'], $rating);
        Flash::set('success', 'Thanks — your rating was saved.');

        return $response
            ->withHeader('Location', $parser->urlFor($listRoute, ['slug' => $slug]) . '#rate')
            ->withStatus(302);
    };

    $postPackageComment = static function (
        Request $request,
        Response $response,
        array $args,
        string $kind
    ) use ($view, $pdo, $comments, $resolvePackage): Response {
        $parser = RouteContext::fromRequest($request)->getRouteParser();
        $slug = (string) ($args['slug'] ?? '');
        $listRoute = $kind === SubmissionKind::PLUGIN
            ? 'public.struxa_catalog.plugin_show'
            : 'public.struxa_catalog.theme_show';

        if ($resolvePackage($kind, $slug) === null) {
            return $response->withStatus(404);
        }

        $actor = CatalogEngagementActor::fromView($pdo, $view());
        if (!$actor['ok']) {
            return $response->withStatus(403);
        }

        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $text = trim((string) ($body['body'] ?? ''));
        if ($text === '') {
            Flash::set('error', 'Comment cannot be empty.');

            return $response
                ->withHeader('Location', $parser->urlFor($listRoute, ['slug' => $slug]) . '#comments')
                ->withStatus(302);
        }
        if (mb_strlen($text) > CatalogCommentRepository::MAX_BODY_LENGTH) {
            Flash::set('error', 'Comment is too long (max ' . CatalogCommentRepository::MAX_BODY_LENGTH . ' characters).');

            return $response
                ->withHeader('Location', $parser->urlFor($listRoute, ['slug' => $slug]) . '#comments')
                ->withStatus(302);
        }

        $comments->insert($kind, $slug, $actor['cms_user_id'], $text);
        Flash::set('success', 'Comment posted.');

        return $response
            ->withHeader('Location', $parser->urlFor($listRoute, ['slug' => $slug]) . '#comments')
            ->withStatus(302);
    };

    $app->get('/plugins/submit', function (Request $request, Response $response) use ($twig, $memberView, $ns): Response {
        return $twig->render($response, $ns . '/public/submit.twig', $memberView([
            'page_title' => 'Submit a plugin',
            'submit_kind' => SubmissionKind::PLUGIN,
            'submit_action' => 'public.struxa_catalog.plugins.submit',
            'catalog_sidebar_nav' => 'plugin',
        ]));
    })->add($requireLoggedIn)->setName('public.struxa_catalog.plugins.submit_form');

    $app->get('/catalog/submissions', function (Request $request, Response $response) use ($twig, $memberView, $ns): Response {
        return $twig->render($response, $ns . '/public/my_submissions.twig', $memberView([
            'page_title' => 'Your catalog submissions',
            'catalog_sidebar_nav' => 'submissions',
        ]));
    })->add($requireLoggedIn)->setName('public.struxa_catalog.my_submissions');

    $app->post('/plugins/submit', function (Request $request, Response $response) use (
        $twig,
        $memberView,
        $ns,
        $pdo,
        $validator,
        $submissions,
        $screenshots
    ): Response {
        return (static function (Request $request, Response $response) use (
            $twig,
            $memberView,
            $ns,
            $pdo,
            $validator,
            $submissions,
            $screenshots
        ): Response {
            $parser = RouteContext::fromRequest($request)->getRouteParser();
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $result = processSubmission($request, $body, $memberView(), SubmissionKind::PLUGIN, $pdo, $validator, $submissions, $screenshots);
            if (!$result['ok']) {
                return $twig->render($response, $ns . '/public/submit.twig', $memberView([
                    'page_title' => 'Submit a plugin',
                    'submit_kind' => SubmissionKind::PLUGIN,
                    'submit_action' => 'public.struxa_catalog.plugins.submit',
                    'catalog_sidebar_nav' => 'plugin',
                    'errors' => $result['errors'],
                    'old' => $result['old'],
                ]));
            }
            Flash::set('success', 'Thank you! Your plugin was submitted for review.');

            return $response->withHeader('Location', $parser->urlFor('public.struxa_catalog.my_submissions'))->withStatus(302);
        })($request, $response);
    })->add($requireLoggedIn)->setName('public.struxa_catalog.plugins.submit');

    $app->get('/themes/submit', function (Request $request, Response $response) use ($twig, $memberView, $ns): Response {
        return $twig->render($response, $ns . '/public/submit.twig', $memberView([
            'page_title' => 'Submit a theme',
            'submit_kind' => SubmissionKind::THEME,
            'submit_action' => 'public.struxa_catalog.themes.submit',
            'catalog_sidebar_nav' => 'theme',
        ]));
    })->add($requireLoggedIn)->setName('public.struxa_catalog.themes.submit_form');

    $app->post('/themes/submit', function (Request $request, Response $response) use (
        $twig,
        $memberView,
        $ns,
        $pdo,
        $validator,
        $submissions,
        $screenshots
    ): Response {
        $parser = RouteContext::fromRequest($request)->getRouteParser();
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $result = processSubmission($request, $body, $memberView(), SubmissionKind::THEME, $pdo, $validator, $submissions, $screenshots);
        if (!$result['ok']) {
            return $twig->render($response, $ns . '/public/submit.twig', $memberView([
                'page_title' => 'Submit a theme',
                'submit_kind' => SubmissionKind::THEME,
                'submit_action' => 'public.struxa_catalog.themes.submit',
                'catalog_sidebar_nav' => 'theme',
                'errors' => $result['errors'],
                'old' => $result['old'],
            ]));
        }
        Flash::set('success', 'Thank you! Your theme was submitted for review.');

        return $response->withHeader('Location', $parser->urlFor('public.struxa_catalog.my_submissions'))->withStatus(302);
    })->add($requireLoggedIn)->setName('public.struxa_catalog.themes.submit');

    $app->get('/plugins/{slug:' . $catalogSlugPattern . '}', function (
        Request $request,
        Response $response,
        array $args
    ) use ($renderPackageShow, $view): Response {
        return $renderPackageShow(
            $response,
            SubmissionKind::PLUGIN,
            (string) ($args['slug'] ?? ''),
            $view()
        );
    })->setName('public.struxa_catalog.plugin_show');

    $app->get('/themes/{slug:' . $catalogSlugPattern . '}', function (
        Request $request,
        Response $response,
        array $args
    ) use ($renderPackageShow, $view): Response {
        return $renderPackageShow(
            $response,
            SubmissionKind::THEME,
            (string) ($args['slug'] ?? ''),
            $view()
        );
    })->setName('public.struxa_catalog.theme_show');

    $app->post('/plugins/{slug:' . $catalogSlugPattern . '}/rate', function (
        Request $request,
        Response $response,
        array $args
    ) use ($postPackageRating, $requireLoggedIn): Response {
        return $postPackageRating($request, $response, $args, SubmissionKind::PLUGIN);
    })->add($requireLoggedIn)->setName('public.struxa_catalog.plugin_rate');

    $app->post('/plugins/{slug:' . $catalogSlugPattern . '}/comment', function (
        Request $request,
        Response $response,
        array $args
    ) use ($postPackageComment, $requireLoggedIn): Response {
        return $postPackageComment($request, $response, $args, SubmissionKind::PLUGIN);
    })->add($requireLoggedIn)->setName('public.struxa_catalog.plugin_comment');

    $app->post('/themes/{slug:' . $catalogSlugPattern . '}/rate', function (
        Request $request,
        Response $response,
        array $args
    ) use ($postPackageRating, $requireLoggedIn): Response {
        return $postPackageRating($request, $response, $args, SubmissionKind::THEME);
    })->add($requireLoggedIn)->setName('public.struxa_catalog.theme_rate');

    $app->post('/themes/{slug:' . $catalogSlugPattern . '}/comment', function (
        Request $request,
        Response $response,
        array $args
    ) use ($postPackageComment, $requireLoggedIn): Response {
        return $postPackageComment($request, $response, $args, SubmissionKind::THEME);
    })->add($requireLoggedIn)->setName('public.struxa_catalog.theme_comment');

    $app->get('/struxa-catalog/download/{kind}/{slug}', function (
        Request $request,
        Response $response,
        array $args
    ) use ($packageDownload): Response {
        $kind = (string) ($args['kind'] ?? '');
        $slug = (string) ($args['slug'] ?? '');

        return $packageDownload->serve($response, $kind, $slug);
    })->setName('public.struxa_catalog.download');

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
 * @param array<string, mixed> $viewData
 * @return array{ok: true}|array{ok: false, errors: list<string>, old: array<string, string>}
 */
function processSubmission(
    Request $request,
    array $body,
    array $viewData,
    string $kind,
    \PDO $pdo,
    CatalogSubmissionValidator $validator,
    CatalogSubmissionRepository $submissions,
    ScreenshotStorage $screenshots,
): array {
    $old = [
        'git_repo_url' => trim((string) ($body['git_repo_url'] ?? '')),
        'git_branch' => trim((string) ($body['git_branch'] ?? 'main')),
    ];
    $errors = [];
    if ($old['git_repo_url'] === '') {
        $errors[] = 'Git repository URL is required.';
    }

    $submitterResult = CatalogSubmitterContext::fromRequest($pdo, $request, $viewData);
    if (!$submitterResult['ok']) {
        return ['ok' => false, 'errors' => array_merge($errors, $submitterResult['errors']), 'old' => $old];
    }
    /** @var CatalogSubmitterContext $submitter */
    $submitter = $submitterResult['ctx'];

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
        $submitter->email,
        $submitter,
    );

    return ['ok' => true];
}
