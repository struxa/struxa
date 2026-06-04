<?php

declare(strict_types=1);

use App\Access\ActivityLogger;
use App\Access\PermissionSlug;
use App\Dist\PackageZipUploadReader;
use App\Event\Events;
use App\Event\StorefrontCachesInvalidateEvent;
use App\Flash;
use App\Cache\CacheManager;
use App\Http\Middleware\RequireCmsStaff;
use App\Http\Middleware\RequirePermission;
use App\Filesystem\SafeDirectoryRemoval;
use App\Plugin\PluginCatalogLoader;
use App\Plugin\PluginManager;
use App\Plugin\PluginMigrationRunner;
use App\Plugin\PluginManifestParser;
use App\Plugin\StruxaCatalogStackShipper;
use App\Security\CsrfToken;
use App\Settings;
use App\Plugin\PluginPerformanceRegistry;
use App\Plugin\PluginRemoteInstaller;
use App\Plugin\PluginRepository;
use App\Plugin\PluginUninstaller;
use App\Plugin\PluginScanner;
use App\Plugin\PluginUpdateChecker;
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
    $pluginUpdateChecker = new PluginUpdateChecker(
        (new CacheManager($root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache'))->internal(),
        $catalogLoader,
    );
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

    $namedRouteUrl = static function (Request $request, string $name): ?string {
        try {
            return RouteContext::fromRequest($request)->getRouteParser()->urlFor($name);
        } catch (\Throwable) {
            return null;
        }
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
        $root,
        $catalogLoader,
        $remoteInstaller,
        $pluginUpdateChecker,
        $pluginPerformance,
        $namedRouteUrl
    ): void {
        $group->get('/extensions/plugins', function (Request $request, Response $response) use (
            $twig,
            $adminContext,
            $withCmsUser,
            $repo,
            $manager,
            $validator,
            $scanner,
            $pluginPerformance,
            $pluginUpdateChecker,
            $namedRouteUrl,
            $root
        ): Response {
            $discovered = $manager->syncDiscoveredToDatabase();
            $catalogBySlug = $pluginUpdateChecker->catalogEntriesBySlug();
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
                'updates' => 0,
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
                $updateStatus = $pluginUpdateChecker->statusFor($p, $catalogBySlug[$p->manifest->slug] ?? null);
                if ($updateStatus['update_available']) {
                    $summary['updates']++;
                }
                $rows[] = [
                    'discovered' => $p,
                    'record' => $db,
                    'is_active' => $isActive,
                    'compatibility' => $report,
                    'performance' => $pluginPerformance->snapshotForSlug($p->manifest->slug),
                    'update' => $updateStatus,
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
                'struxa_catalog_submissions_url' => $namedRouteUrl($request, 'admin.struxa_catalog.submissions'),
                'struxa_catalog_ship_stack_url' => RouteContext::fromRequest($request)
                    ->getRouteParser()
                    ->urlFor('admin.extensions.plugins.ship_struxa_catalog_stack'),
                'struxa_catalog_admin_disk_version' => StruxaCatalogStackShipper::diskVersion($root),
                'struxa_catalog_admin_repo_version' => StruxaCatalogStackShipper::repoVersion(
                    StruxaCatalogStackShipper::resolveDistRoot($root)
                ),
                'struxa_catalog_show_repair' => $scanner->findBySlug('struxa-admin') !== null
                    && $namedRouteUrl($request, 'admin.struxa_catalog.submissions') === null,
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
            $repo,
            $namedRouteUrl
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
            $struxaAdminActive = $struxaAdminOnDisk
                && $struxaAdminRow !== null
                && $struxaAdminRow->isActive;

            $catalogPlugins = $loaded['ok'] ? $loaded['entries'] : [];
            $catalogInstalledCount = 0;
            foreach ($catalogPlugins as $entry) {
                if (isset($installed[$entry->slug])) {
                    ++$catalogInstalledCount;
                }
            }

            return $twig->render($response, 'admin/plugins/browse.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'extensions_plugins',
                'catalog_ok' => $loaded['ok'],
                'catalog_error' => $loaded['ok'] ? null : $loaded['error'],
                'catalog_plugins' => $catalogPlugins,
                'catalog_plugin_count' => count($catalogPlugins),
                'catalog_installed_count' => $catalogInstalledCount,
                'installed_plugin_slugs' => $installed,
                'struxa_admin_on_disk' => $struxaAdminOnDisk,
                'struxa_admin_active' => $struxaAdminActive,
                'struxa_catalog_submissions_url' => $namedRouteUrl($request, 'admin.struxa_catalog.submissions'),
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

        $group->post('/extensions/plugins/install-upload', function (Request $request, Response $response) use (
            $remoteInstaller,
            $manager
        ): Response {
            $parser = RouteContext::fromRequest($request)->getRouteParser();
            $backBrowse = $parser->urlFor('admin.extensions.plugins.browse');
            $uploaded = PackageZipUploadReader::read($request->getUploadedFiles(), 45_000_000);
            if ($uploaded['ok'] !== true) {
                Flash::set('error', $uploaded['error']);

                return $response->withHeader('Location', $backBrowse)->withStatus(302);
            }
            $err = $remoteInstaller->installFromUploadedZip($uploaded['body']);
            if ($err !== null) {
                Flash::set('error', $err);

                return $response->withHeader('Location', $backBrowse)->withStatus(302);
            }
            $manager->syncDiscoveredToDatabase();
            Flash::set('success', 'Plugin installed from upload. Activate it from the plugins list to load routes and run migrations.');

            return $response
                ->withHeader('Location', $parser->urlFor('admin.extensions.plugins.index'))
                ->withStatus(302);
        })->setName('admin.extensions.plugins.install_upload');

        $group->post('/extensions/plugins/update', function (Request $request, Response $response) use (
            $catalogLoader,
            $remoteInstaller,
            $pluginUpdateChecker,
            $manager,
            $scanner,
            $repo,
            $validator,
            $migrationRunner,
            $activity,
            $cmsUid
        ): Response {
            $parser = RouteContext::fromRequest($request)->getRouteParser();
            $back = $parser->urlFor('admin.extensions.plugins.index');
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $slug = strtolower(trim((string) ($body['slug'] ?? '')));
            if ($slug === '' || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
                Flash::set('error', 'Invalid plugin.');

                return $response->withHeader('Location', $back)->withStatus(302);
            }

            $discovered = $scanner->findBySlug($slug);
            if ($discovered === null) {
                Flash::set('error', 'Plugin not found on disk.');

                return $response->withHeader('Location', $back)->withStatus(302);
            }

            $catalogBySlug = $pluginUpdateChecker->catalogEntriesBySlug();
            $updateStatus = $pluginUpdateChecker->statusFor($discovered, $catalogBySlug[$slug] ?? null);
            if (!$updateStatus['update_available']) {
                Flash::set('error', 'No update is available for this plugin.');

                return $response->withHeader('Location', $back)->withStatus(302);
            }
            if (!$updateStatus['can_update']) {
                Flash::set('error', 'An update was detected but no catalog or GitHub source is configured to download it.');

                return $response->withHeader('Location', $back)->withStatus(302);
            }

            $db = $repo->findBySlug($slug);
            $wasActive = $db !== null && $db->isActive;
            if ($wasActive) {
                $repo->setActive($slug, false);
            }

            $err = null;
            $repoUrl = $discovered->manifest->repositoryUrl ?? '';
            $github = is_string($repoUrl) ? PluginUpdateChecker::parseGithubRepositoryUrl($repoUrl) : null;
            $catalogEntry = $catalogBySlug[$slug] ?? null;

            $updateFromCatalog = static function () use (
                $catalogLoader,
                $remoteInstaller,
                $slug
            ): ?string {
                $loaded = $catalogLoader->load();
                if (!$loaded['ok']) {
                    return 'Plugin catalog is unavailable: ' . $loaded['error'];
                }

                return $remoteInstaller->updateFromCatalogSlug($slug, $loaded['entries']);
            };

            if ($updateStatus['source'] === 'catalog' && $catalogEntry !== null) {
                $err = $updateFromCatalog();
            } elseif ($github !== null) {
                $err = $remoteInstaller->updateFromGithubRepository(
                    $slug,
                    $github['owner'],
                    $github['repo'],
                    PluginUpdateChecker::resolveGithubRef(),
                );
                if ($err !== null && $catalogEntry !== null) {
                    $catalogErr = $updateFromCatalog();
                    if ($catalogErr === null) {
                        $err = null;
                    }
                }
            } elseif ($catalogEntry !== null) {
                $err = $updateFromCatalog();
            } else {
                $err = 'This plugin has no GitHub repository URL or catalog entry for updates.';
            }

            if ($err !== null) {
                Flash::set('error', $err);
                if ($wasActive) {
                    $repo->setActive($slug, true);
                }

                return $response->withHeader('Location', $back)->withStatus(302);
            }

            $manager->syncDiscoveredToDatabase();
            $discovered = $scanner->findBySlug($slug);
            if ($discovered === null) {
                Flash::set('error', 'Plugin update finished but the package could not be re-read from disk.');

                return $response->withHeader('Location', $back)->withStatus(302);
            }

            $repo->upsertFromManifest($discovered->manifest);
            try {
                $migrationRunner->runPending($slug, $discovered->rootPath . '/migrations');
            } catch (\Throwable $e) {
                Flash::set('error', 'Plugin updated but migrations failed: ' . $e->getMessage());

                return $response->withHeader('Location', $back)->withStatus(302);
            }

            $reactivated = false;
            if ($wasActive) {
                $manager->registerAutoloadForPlugin($discovered);
                $report = $validator->compatibilityReport($discovered, $scanner);
                if ($report->canActivate()) {
                    $repo->setActive($slug, true);
                    $reactivated = true;
                } else {
                    Flash::set(
                        'error',
                        'Plugin updated to v' . $discovered->manifest->version
                        . ' but was left inactive: ' . implode(' ', $report->activationErrors())
                    );
                    Events::dispatch(new StorefrontCachesInvalidateEvent('plugin_updated'));

                    return $response->withHeader('Location', $back)->withStatus(302);
                }
            }

            $activity->log($cmsUid($request), 'plugin.updated', 'plugin', null, [
                'slug' => $slug,
                'version' => $discovered->manifest->version,
                'source' => $updateStatus['source'],
            ]);
            $msg = 'Plugin updated to v' . $discovered->manifest->version . '.';
            if ($wasActive && $reactivated) {
                $msg .= ' It remains active.';
            } elseif (!$wasActive) {
                $msg .= ' Activate it when you are ready.';
            }
            Flash::set('success', $msg);
            Events::dispatch(new StorefrontCachesInvalidateEvent('plugin_updated'));

            return $response->withHeader('Location', $back)->withStatus(302);
        })->setName('admin.extensions.plugins.update');

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

        $group->post('/extensions/plugins/purge-orphan', function (Request $request, Response $response) use (
            $scanner,
            $repo,
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
            if ($scanner->findBySlug($slug) !== null) {
                Flash::set('error', 'Plugin folder still exists on disk. Use Remove on the installed plugins list instead.');

                return $response->withHeader('Location', $back)->withStatus(302);
            }
            if ($repo->findBySlug($slug) === null) {
                Flash::set('error', 'No database row for that plugin.');

                return $response->withHeader('Location', $back)->withStatus(302);
            }
            $repo->deleteBySlug($slug);
            $scanner->clearDiscoverCache();
            $activity->log($cmsUid($request), 'plugin.orphan_purged', 'plugin', null, ['slug' => $slug]);
            Flash::set('success', 'Removed database record for "' . $slug . '". You can reinstall from the catalog when ready.');

            return $response->withHeader('Location', $back)->withStatus(302);
        })->setName('admin.extensions.plugins.purge_orphan');

        $group->post('/extensions/plugins/repair-struxa-catalog', function (Request $request, Response $response) use (
            $manager,
            $migrationRunner,
            $activity,
            $cmsUid
        ): Response {
            $parser = RouteContext::fromRequest($request)->getRouteParser();
            $back = $parser->urlFor('admin.extensions.plugins.index');
            $result = $manager->repairStruxaCatalogAdmin($migrationRunner);
            if (!$result['ok']) {
                Flash::set('error', $result['error']);

                return $response->withHeader('Location', $back)->withStatus(302);
            }

            $activity->log($cmsUid($request), 'plugin.repaired', 'plugin', null, ['slug' => 'struxa-admin']);
            if (!class_exists(\App\Plugin\StruxaCatalogAdminRouteRegistrar::class)) {
                Flash::set(
                    'error',
                    'Database repaired, but catalog admin URLs need CMS file '
                    . 'src/Plugin/StruxaCatalogAdminRouteRegistrar.php (Struxa 1.1.56+). '
                    . 'Upload it via terminal (see struxa repo), then reload this page. '
                    . 'Also ensure bootstrap/web_app.php is not still commenting out registerStruxaCatalogAdminRoutesIfNeeded.'
                );
            } else {
                Flash::set(
                    'success',
                    'Struxa Catalog Admin repaired. Reload this page once — Catalog submissions should appear in the toolbar and under Extensions.'
                );
            }
            Events::dispatch(new StorefrontCachesInvalidateEvent('plugin_repaired'));

            return $response->withHeader('Location', $back)->withStatus(302);
        })->setName('admin.extensions.plugins.repair_struxa_catalog');

        $group->post('/extensions/plugins/ship-struxa-catalog-stack', function (Request $request, Response $response) use (
            $root,
            $pdo,
            $scanner,
            $repo,
            $migrationRunner,
            $activity,
            $cmsUid,
            $namedRouteUrl
        ): Response {
            $parser = RouteContext::fromRequest($request)->getRouteParser();
            $back = $namedRouteUrl($request, 'admin.struxa_catalog.submissions')
                ?? $parser->urlFor('admin.extensions.plugins.index');
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $token = isset($body['_csrf_token']) && is_string($body['_csrf_token']) ? $body['_csrf_token'] : '';
            if (!CsrfToken::validate($token)) {
                Flash::set('error', 'Invalid security token. Please try again.');

                return $response->withHeader('Location', $back)->withStatus(302);
            }

            $shipper = new StruxaCatalogStackShipper($root, $pdo, $scanner, $repo, $migrationRunner);
            $result = $shipper->ship();
            if (!$result['ok']) {
                Flash::set('error', $result['error']);

                return $response->withHeader('Location', $back)->withStatus(302);
            }

            $msg = implode(' ', $result['messages']);
            if (!empty($result['reload_recommended'])) {
                $msg .= ' Reload Extensions → Plugins and Catalog submissions.';
            }
            Flash::set('success', $msg);
            $activity->log($cmsUid($request), 'plugin.catalog_stack_ship', 'plugin', null, [
                'slug' => 'struxa-admin',
                'version' => $result['version'] ?? '',
            ]);
            Events::dispatch(new StorefrontCachesInvalidateEvent('catalog_stack_ship'));

            return $response->withHeader('Location', $back)->withStatus(302);
        })->setName('admin.extensions.plugins.ship_struxa_catalog_stack');
    })->add($permPlugins)->add($middleware);
};
