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
use App\Plugin\PluginCatalogLoader;
use App\Plugin\PluginManager;
use App\Plugin\PluginMigrationRunner;
use App\Plugin\PluginPerformanceRegistry;
use App\Plugin\PluginRemoteInstaller;
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
    $root = dirname(__DIR__);
    $activity = new ActivityLogger($pdo);
    $repo = new PluginRepository($pdo);
    $scanner = new PluginScanner($root);
    $validator = new PluginValidator($pdo);
    $manager = new PluginManager($root, $repo, $scanner, $validator);
    $migrationRunner = new PluginMigrationRunner($pdo);
    $catalogLoader = new PluginCatalogLoader($root);
    $remoteInstaller = new PluginRemoteInstaller($root . '/plugins', $scanner, $root);
    $pluginPerformance = PluginPerformanceRegistry::instance();

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
        $pdo,
        $catalogLoader,
        $remoteInstaller,
        $pluginPerformance
    ): void {
        $group->get('/extensions/plugins', function (Request $request, Response $response) use (
            $twig,
            $adminContext,
            $withCmsUser,
            $repo,
            $manager,
            $validator,
            $scanner,
            $pluginPerformance
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
            $summary = [
                'total' => 0,
                'active' => 0,
                'inactive' => 0,
                'blocked' => 0,
                'warnings' => 0,
            ];
            foreach ($discovered as $p) {
                $db = $repo->findBySlug($p->manifest->slug);
                $isActive = $db !== null && $db->isActive;
                $report = $validator->compatibilityReport($p, $scanner);
                $summary['total']++;
                if ($isActive) {
                    $summary['active']++;
                } else {
                    $summary['inactive']++;
                }
                if ($report->statusLabel() === 'blocked') {
                    $summary['blocked']++;
                } elseif ($report->statusLabel() === 'warnings') {
                    $summary['warnings']++;
                }
                $rows[] = [
                    'discovered' => $p,
                    'record' => $db,
                    'is_active' => $isActive,
                    'compatibility' => $report,
                    'performance' => $pluginPerformance->snapshotForSlug($p->manifest->slug),
                ];
            }

            usort($rows, static function (array $a, array $b): int {
                $aActive = $a['is_active'] ? 0 : 1;
                $bActive = $b['is_active'] ? 0 : 1;
                if ($aActive !== $bActive) {
                    return $aActive <=> $bActive;
                }

                return strcasecmp($a['discovered']->manifest->name, $b['discovered']->manifest->name);
            });

            return $twig->render($response, 'admin/plugins/index.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'extensions_plugins',
                'plugin_rows' => $rows,
                'plugin_summary' => $summary,
                'plugin_orphans' => $orphans,
                'plugin_perf_thresholds' => [
                    'boot_ms' => PluginPerformanceRegistry::BOOT_SLOW_MS,
                    'hook_ms' => PluginPerformanceRegistry::HOOK_SLOW_MS,
                ],
                'plugin_circuit_breaker' => PluginPerformanceRegistry::circuitBreakerEnabled(),
            ])));
        })->setName('admin.extensions.plugins.index');

        $group->get('/extensions/plugins/browse', function (Request $request, Response $response) use (
            $twig,
            $adminContext,
            $withCmsUser,
            $catalogLoader,
            $scanner,
            $repo
        ): Response {
            $loaded = $catalogLoader->load();
            $installed = [];
            $struxaAdminOnDisk = false;
            $struxaAdminActive = false;
            foreach ($scanner->discover() as $p) {
                $installed[$p->manifest->slug] = true;
                if ($p->manifest->slug === 'struxa-admin') {
                    $struxaAdminOnDisk = true;
                }
            }
            $struxaAdminRow = $repo->findBySlug('struxa-admin');
            $struxaAdminActive = $struxaAdminRow !== null && $struxaAdminRow->isActive;

            return $twig->render($response, 'admin/plugins/browse.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'extensions_plugins',
                'catalog_ok' => $loaded['ok'],
                'catalog_error' => $loaded['ok'] ? null : $loaded['error'],
                'catalog_plugins' => $loaded['ok'] ? $loaded['entries'] : [],
                'installed_plugin_slugs' => $installed,
                'struxa_admin_on_disk' => $struxaAdminOnDisk,
                'struxa_admin_active' => $struxaAdminActive,
            ])));
        })->setName('admin.extensions.plugins.browse');

        $group->post('/extensions/plugins/install-from-catalog', function (Request $request, Response $response) use (
            $catalogLoader,
            $remoteInstaller,
            $manager
        ): Response {
            $parser = RouteContext::fromRequest($request)->getRouteParser();
            $backBrowse = $parser->urlFor('admin.extensions.plugins.browse');
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $slug = strtolower(trim((string) ($body['plugin_slug'] ?? '')));
            $loaded = $catalogLoader->load();
            if (!$loaded['ok']) {
                Flash::set('error', 'Plugin catalog is unavailable: ' . $loaded['error']);

                return $response->withHeader('Location', $backBrowse)->withStatus(302);
            }
            $err = $remoteInstaller->installFromCatalogSlug($slug, $loaded['entries']);
            if ($err !== null) {
                Flash::set('error', $err);

                return $response->withHeader('Location', $backBrowse)->withStatus(302);
            }
            $manager->syncDiscoveredToDatabase();
            Flash::set('success', 'Plugin installed. Activate it from the plugins list to load routes and run migrations.');

            return $response
                ->withHeader('Location', $parser->urlFor('admin.extensions.plugins.index'))
                ->withStatus(302);
        })->setName('admin.extensions.plugins.install_from_catalog');

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
            $report = $validator->compatibilityReport($discovered, $scanner);
            if (!$report->canActivate()) {
                Flash::set('error', 'Cannot activate ' . $slug . ': ' . implode(' ', $report->activationErrors()));

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
