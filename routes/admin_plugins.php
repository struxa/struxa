<?php

declare(strict_types=1);

use App\Access\ActivityLogger;
use App\Access\PermissionSlug;
use App\Event\Events;
use App\Event\StorefrontCachesInvalidateEvent;
use App\Flash;
use App\Http\Middleware\RequireCmsStaff;
use App\Http\Middleware\RequirePermission;
use App\Filesystem\SafeDirectoryRemoval;
use App\Plugin\PluginManager;
use App\Plugin\PluginMigrationRunner;
use App\Plugin\PluginRepository;
use App\Plugin\PluginUninstaller;
use App\Plugin\PluginScanner;
use App\Plugin\PluginValidator;
use PHPAuth\Auth;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Routing\RouteContext;
use Slim\Views\Twig;

/**
 * @param callable(): array<string, mixed> $viewData
 */
return static function (App $app, Twig $twig, Auth $auth, \PDO $pdo, callable $viewData): void {
    $middleware = new RequireCmsStaff($auth, $pdo);
    $permPlugins = new RequirePermission($pdo, [PermissionSlug::MANAGE_PLUGINS]);
    $activity = new ActivityLogger($pdo);
    $repo = new PluginRepository($pdo);
    $scanner = new PluginScanner(dirname(__DIR__));
    $validator = new PluginValidator();
    $manager = new PluginManager(dirname(__DIR__), $repo, $scanner, $validator);
    $migrationRunner = new PluginMigrationRunner($pdo);

    $adminContext = static fn (): array => array_merge($viewData(), []);
    $withCmsUser = static function (Request $request, array $data): array {
        /** @var array<string, mixed> $cmsUser */
        $cmsUser = $request->getAttribute('cms_user') ?? [];

        return array_merge($data, ['cms_user' => $cmsUser]);
    };

    $cmsUid = static function (Request $request): ?int {
        /** @var array<string, mixed> $u */
        $u = $request->getAttribute('cms_user') ?? [];
        $id = isset($u['id']) ? (int) $u['id'] : 0;

        return $id > 0 ? $id : null;
    };

    $app->group('/admin', function (\Slim\Routing\RouteCollectorProxy $group) use (
        $twig,
        $adminContext,
        $withCmsUser,
        $repo,
        $manager,
        $scanner,
        $validator,
        $migrationRunner,
        $activity,
        $cmsUid,
        $pdo
    ): void {
        $group->get('/extensions/plugins', function (Request $request, Response $response) use (
            $twig,
            $adminContext,
            $withCmsUser,
            $repo,
            $manager
        ): Response {
            $discovered = $manager->syncDiscoveredToDatabase();
            $slugs = [];
            foreach ($discovered as $p) {
                $slugs[$p->manifest->slug] = true;
            }
            $orphans = [];
            foreach ($repo->allOrdered() as $row) {
                if (!isset($slugs[$row->slug])) {
                    $orphans[] = $row;
                }
            }
            $rows = [];
            foreach ($discovered as $p) {
                $db = $repo->findBySlug($p->manifest->slug);
                $rows[] = [
                    'discovered' => $p,
                    'record' => $db,
                    'is_active' => $db !== null && $db->isActive,
                ];
            }

            return $twig->render($response, 'admin/plugins/index.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'extensions_plugins',
                'plugin_rows' => $rows,
                'plugin_orphans' => $orphans,
            ])));
        })->setName('admin.extensions.plugins.index');

        $group->post('/extensions/plugins/activate', function (Request $request, Response $response) use (
            $repo,
            $manager,
            $scanner,
            $validator,
            $migrationRunner,
            $activity,
            $cmsUid
        ): Response {
            $body = $request->getParsedBody();
            $slug = is_array($body) ? trim((string) ($body['slug'] ?? '')) : '';
            $parser = RouteContext::fromRequest($request)->getRouteParser();
            $back = $parser->urlFor('admin.extensions.plugins.index');
            if ($slug === '' || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
                Flash::set('error', 'Invalid plugin.');

                return $response->withHeader('Location', $back)->withStatus(302);
            }
            $discovered = $scanner->findBySlug($slug);
            if ($discovered === null) {
                Flash::set('error', 'Plugin not found on disk.');

                return $response->withHeader('Location', $back)->withStatus(302);
            }

            $manager->registerAutoloadForPlugin($discovered);
            $errors = $validator->activationErrors($discovered, $scanner);
            if ($errors !== []) {
                Flash::set('error', implode(' ', $errors));

                return $response->withHeader('Location', $back)->withStatus(302);
            }

            $repo->upsertFromManifest($discovered->manifest);
            try {
                $migrationRunner->runPending($slug, $discovered->rootPath . '/migrations');
            } catch (\Throwable $e) {
                Flash::set('error', 'Plugin migrations failed: ' . $e->getMessage());

                return $response->withHeader('Location', $back)->withStatus(302);
            }

            $repo->setActive($slug, true);
            $activity->log($cmsUid($request), 'plugin.activated', 'plugin', null, ['slug' => $slug]);
            Flash::set('success', 'Plugin activated.');
            Events::dispatch(new StorefrontCachesInvalidateEvent('plugin_activated'));

            return $response->withHeader('Location', $back)->withStatus(302);
        })->setName('admin.extensions.plugins.activate');

        $group->post('/extensions/plugins/deactivate', function (Request $request, Response $response) use ($repo, $activity, $cmsUid): Response {
            $body = $request->getParsedBody();
            $slug = is_array($body) ? trim((string) ($body['slug'] ?? '')) : '';
            $parser = RouteContext::fromRequest($request)->getRouteParser();
            $back = $parser->urlFor('admin.extensions.plugins.index');
            if ($slug === '' || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
                Flash::set('error', 'Invalid plugin.');

                return $response->withHeader('Location', $back)->withStatus(302);
            }
            $repo->setActive($slug, false);
            $activity->log($cmsUid($request), 'plugin.deactivated', 'plugin', null, ['slug' => $slug]);
            Flash::set('success', 'Plugin deactivated.');
            Events::dispatch(new StorefrontCachesInvalidateEvent('plugin_deactivated'));

            return $response->withHeader('Location', $back)->withStatus(302);
        })->setName('admin.extensions.plugins.deactivate');

        $group->post('/extensions/plugins/remove', function (Request $request, Response $response) use (
            $repo,
            $scanner,
            $migrationRunner,
            $activity,
            $cmsUid,
            $pdo
        ): Response {
            $body = $request->getParsedBody();
            $slug = is_array($body) ? trim((string) ($body['slug'] ?? '')) : '';
            $parser = RouteContext::fromRequest($request)->getRouteParser();
            $back = $parser->urlFor('admin.extensions.plugins.index');
            if ($slug === '' || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
                Flash::set('error', 'Invalid plugin.');

                return $response->withHeader('Location', $back)->withStatus(302);
            }
            $discovered = $scanner->findBySlug($slug);
            if ($discovered === null) {
                Flash::set('error', 'Plugin folder not found on disk.');

                return $response->withHeader('Location', $back)->withStatus(302);
            }

            $pluginsRoot = dirname(__DIR__) . '/plugins';
            $pluginsRootReal = realpath($pluginsRoot);
            if ($pluginsRootReal === false) {
                Flash::set('error', 'Plugins directory is missing.');

                return $response->withHeader('Location', $back)->withStatus(302);
            }

            $repo->setActive($slug, false);

            try {
                (new PluginUninstaller($pdo, $migrationRunner))->uninstall($slug, $discovered->rootPath);
            } catch (\Throwable $e) {
                Flash::set('error', 'Plugin uninstall SQL failed: ' . $e->getMessage());

                return $response->withHeader('Location', $back)->withStatus(302);
            }

            $rm = SafeDirectoryRemoval::removeIfInside($discovered->rootPath, $pluginsRootReal);
            if ($rm !== null) {
                Flash::set('error', 'Could not remove plugin files: ' . $rm);

                return $response->withHeader('Location', $back)->withStatus(302);
            }

            $scanner->clearDiscoverCache();
            $repo->deleteBySlug($slug);
            $activity->log($cmsUid($request), 'plugin.removed', 'plugin', null, ['slug' => $slug]);
            Flash::set('success', 'Plugin removed from disk and database.');
            Events::dispatch(new StorefrontCachesInvalidateEvent('plugin_removed'));

            return $response->withHeader('Location', $back)->withStatus(302);
        })->setName('admin.extensions.plugins.remove');
    })->add($permPlugins)->add($middleware);
};
