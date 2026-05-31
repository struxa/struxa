<?php

declare(strict_types=1);

use App\Access\PermissionSlug;
use App\Flash;
use App\Http\Middleware\RequireCmsStaff;
use App\Http\Middleware\RequirePermission;
use App\Section\SectionManager;
use App\Section\SectionPatternHost;
use App\Section\SectionPatternRepository;
use App\Section\SectionPatternSlugger;
use PHPAuth\Auth;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Exception\HttpNotFoundException;
use Slim\Routing\RouteContext;
use Slim\Views\Twig;

/**
 * @param callable(): array<string, mixed> $viewData
 */
return static function (App $app, Twig $twig, Auth $auth, \PDO $pdo, callable $viewData): void {
    $middleware = new RequireCmsStaff($auth, $pdo);
    $perm = new RequirePermission($pdo, [PermissionSlug::MANAGE_PAGES]);
    $patterns = new SectionPatternRepository($pdo);
    $sections = new SectionManager();

    $adminContext = static fn (): array => array_merge($viewData(), []);
    $withCmsUser = static function (Request $request, array $data): array {
        /** @var array<string, mixed> $cmsUser */
        $cmsUser = $request->getAttribute('cms_user') ?? [];

        return array_merge($data, ['cms_user' => $cmsUser]);
    };

    $sectionLabels = [];
    foreach ($sections->definitions() as $key => $def) {
        $sectionLabels[$key] = (string) ($def['label'] ?? $key);
    }

    $app->group('/admin', function (\Slim\Routing\RouteCollectorProxy $group) use (
        $twig,
        $adminContext,
        $withCmsUser,
        $patterns,
        $sectionLabels
    ): void {
        $group->get('/sections/patterns', function (Request $request, Response $response) use (
            $twig,
            $adminContext,
            $withCmsUser,
            $patterns,
            $sectionLabels
        ): Response {
            return $twig->render($response, 'admin/sections/patterns/index.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'section_patterns',
                'pattern_rows' => $patterns->listAll(),
                'section_labels' => $sectionLabels,
            ])));
        })->setName('admin.sections.patterns.index');

        $group->get('/sections/patterns/{id:[0-9]+}/edit', function (Request $request, Response $response, array $args) use (
            $twig,
            $adminContext,
            $withCmsUser,
            $patterns,
            $sectionLabels
        ): Response {
            $id = (int) $args['id'];
            $pattern = $patterns->findById($id);
            if ($pattern === null) {
                throw new HttpNotFoundException($request);
            }

            return $twig->render($response, 'admin/sections/patterns/form.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'section_patterns',
                'pattern' => $pattern,
                'section_labels' => $sectionLabels,
                'errors' => [],
                'old' => null,
            ])));
        })->setName('admin.sections.patterns.edit');

        $group->post('/sections/patterns/{id:[0-9]+}/edit', function (Request $request, Response $response, array $args) use (
            $twig,
            $adminContext,
            $withCmsUser,
            $patterns,
            $sectionLabels
        ): Response {
            $id = (int) $args['id'];
            $pattern = $patterns->findById($id);
            if ($pattern === null) {
                throw new HttpNotFoundException($request);
            }

            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $name = trim((string) ($body['name'] ?? ''));
            $description = trim((string) ($body['description'] ?? ''));
            $host = trim((string) ($body['host'] ?? SectionPatternHost::BOTH));
            $errors = [];

            if ($name === '') {
                $errors['name'] = 'Name is required.';
            }
            if (!SectionPatternHost::isValid($host)) {
                $errors['host'] = 'Choose a valid scope.';
            }

            $slugInput = trim((string) ($body['slug'] ?? ''));
            $slugBase = $slugInput !== '' ? SectionPatternSlugger::slugify($slugInput) : SectionPatternSlugger::slugify($name);
            $slug = SectionPatternSlugger::ensureUnique($patterns, $slugBase, $id);

            if ($errors !== []) {
                return $twig->render($response, 'admin/sections/patterns/form.twig', $withCmsUser($request, array_merge($adminContext(), [
                    'admin_nav' => 'section_patterns',
                    'pattern' => $pattern,
                    'section_labels' => $sectionLabels,
                    'errors' => $errors,
                    'old' => [
                        'name' => $name,
                        'slug' => $slugInput !== '' ? $slugInput : $slugBase,
                        'description' => $description,
                        'host' => $host,
                    ],
                ])));
            }

            $patterns->updateMeta(
                $id,
                $name,
                $slug,
                $description !== '' ? $description : null,
                $host,
            );
            Flash::set('success', 'Pattern updated.');

            return $response
                ->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.sections.patterns.index'))
                ->withStatus(302);
        })->setName('admin.sections.patterns.update');

        $group->post('/sections/patterns/{id:[0-9]+}/delete', function (Request $request, Response $response, array $args) use ($patterns): Response {
            $id = (int) $args['id'];
            if ($patterns->findById($id) !== null) {
                $patterns->delete($id);
                Flash::set('success', 'Pattern deleted.');
            }

            return $response
                ->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.sections.patterns.index'))
                ->withStatus(302);
        })->setName('admin.sections.patterns.delete');
    })->add($perm)->add($middleware);
};
