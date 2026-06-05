<?php

declare(strict_types=1);

namespace App\Plugin;

use App\Access\PermissionSlug;
use App\Dist\ZipExtension;
use App\Media\MediaRepository;
use App\Media\MediaUploadService;
use App\Event\EventDispatcher;
use App\Event\Events;
use App\Event\StorefrontCachesInvalidateEvent;
use App\Flash;
use App\Security\CsrfToken;
use App\Http\Middleware\RequireCmsStaff;
use App\Http\Middleware\RequirePermission;
use PHPAuth\Auth;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Exception\HttpNotFoundException;
use Slim\Routing\RouteContext;
use Slim\Views\Twig;
use StruxaAdmin\CatalogMemberLookup;
use StruxaAdmin\CatalogPublisher;
use StruxaAdmin\CatalogRepoJsonImporter;
use StruxaAdmin\CatalogSettings;
use StruxaAdmin\CatalogSubmissionEditor;
use StruxaAdmin\CatalogSubmissionRepository;
use StruxaAdmin\CatalogSubmissionScreenshotApplier;
use StruxaAdmin\GitHubRepoClient;
use StruxaAdmin\ScreenshotStorage;
use StruxaAdmin\SubmissionStatus;
use Twig\Loader\FilesystemLoader;

/**
 * Registers struxa-admin catalog admin routes from core (Slim {@see RouteCollectorProxy::add()}),
 * so hosts where plugin routes/admin.php fails still get named routes after a CMS update.
 */
final class StruxaCatalogAdminRouteRegistrar
{
    private const ROUTE_SUBMISSIONS = 'admin.struxa_catalog.submissions';

    private static ?string $lastRegisterError = null;

    /** CMS install root (parent of {@code src/}). Avoids nested Slim route closures losing {@code $root}. */
    private static function cmsProjectRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    public static function lastRegisterError(): ?string
    {
        return self::$lastRegisterError;
    }

    /**
     * @param callable(): array<string, mixed> $viewData
     */
    /**
     * Human-readable reason when {@see registerIfNeeded()} would not register routes (for CLI diagnostics).
     */
    public static function skipReason(
        App $app,
        Twig $twig,
        PluginRepository $plugins,
        PluginScanner $scanner,
    ): ?string {
        if (self::namedRouteExists($app, self::ROUTE_SUBMISSIONS)) {
            return 'already_registered';
        }

        $discovered = $scanner->findBySlug('struxa-admin');
        if ($discovered === null) {
            return 'plugin_not_on_disk';
        }

        if ($plugins->findBySlug('struxa-admin') === null) {
            return 'no_cms_plugins_row';
        }

        $loader = $twig->getEnvironment()->getLoader();
        if (!$loader instanceof FilesystemLoader) {
            return 'twig_loader_not_filesystem:' . $loader::class;
        }

        PluginManager::registerPsr4Autoload($discovered);
        if (!class_exists(CatalogSettings::class)) {
            return 'catalog_settings_class_not_loadable';
        }

        return null;
    }

    public static function registerIfNeeded(
        App $app,
        Twig $twig,
        Auth $auth,
        \PDO $pdo,
        callable $viewData,
        EventDispatcher $events,
        string $projectRoot,
        PluginRepository $plugins,
        PluginScanner $scanner,
    ): void {
        $skip = self::skipReason($app, $twig, $plugins, $scanner);
        if ($skip !== null) {
            error_log('[plugin] Catalog admin routes skipped: ' . $skip);

            return;
        }

        $discovered = $scanner->findBySlug('struxa-admin');
        if ($discovered === null) {
            return;
        }

        $loader = $twig->getEnvironment()->getLoader();
        if (!$loader instanceof FilesystemLoader) {
            return;
        }

        $views = $discovered->rootPath . '/views';
        if (is_dir($views)) {
            $loader->addPath($views, PluginManager::twigNamespaceForSlug('struxa-admin'));
        }

        $ctx = new PluginBootContext(
            $projectRoot,
            $discovered->rootPath,
            $discovered->manifest,
            $pdo,
            $app,
            $twig,
            $viewData,
            $auth,
            $events,
            PluginAdminNavRegistry::instance(),
        );

        try {
            self::$lastRegisterError = null;
            self::register($app, $ctx, $auth);
        } catch (\Throwable $e) {
            self::$lastRegisterError = $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
            error_log('[plugin] Core catalog admin routes failed: ' . self::$lastRegisterError);
        }
    }

    /**
     * Sidebar links come from plugin boot; if boot fails, still show catalog admin when core routes exist.
     */
    public static function ensureAdminNavItems(App $app, PluginScanner $scanner): void
    {
        if (!self::namedRouteExists($app, self::ROUTE_SUBMISSIONS)) {
            return;
        }

        $discovered = $scanner->findBySlug('struxa-admin');
        if ($discovered === null) {
            return;
        }

        $registry = PluginAdminNavRegistry::instance();
        $hasSubmissions = false;
        $hasSettings = false;
        foreach ($registry->all() as $item) {
            $route = $item['route_name'] ?? '';
            if ($route === self::ROUTE_SUBMISSIONS) {
                $hasSubmissions = true;
            }
            if ($route === 'admin.struxa_catalog.settings') {
                $hasSettings = true;
            }
        }

        // Nested under Struxa Catalog Admin (matches plugin boot when nested_admin_nav is true).
        if (!$hasSubmissions) {
            $registry->register('struxa-admin', 'Catalog submissions', self::ROUTE_SUBMISSIONS, [], 'struxa-admin', true);
        }
        if (!$hasSettings) {
            $registry->register('struxa-admin', 'Catalog settings', 'admin.struxa_catalog.settings', [], 'struxa-admin', true);
        }
    }

    public static function register(App $app, PluginBootContext $ctx, Auth $auth): void
    {
        if (self::namedRouteExists($app, self::ROUTE_SUBMISSIONS)) {
            return;
        }

        $twig = $ctx->twig();
        $pdo = $ctx->pdo();
        $root = $ctx->projectRoot();
        $ns = '@plugin_struxa_admin';

        $settings = new CatalogSettings($pdo, $root);
        $submissions = new CatalogSubmissionRepository($pdo);
        $github = new GitHubRepoClient($settings->githubToken());
        $publisher = new CatalogPublisher($settings, $submissions, $github);
        $scanner = new PluginScanner($root);
        $discovered = $scanner->findBySlug('struxa-admin');
        $pluginRoot = $discovered !== null ? $discovered->rootPath : $root . '/plugins/struxa-admin';
        $screenshotStorage = new ScreenshotStorage($pluginRoot);
        $mediaRepo = new MediaRepository($pdo);
        $screenshotApplier = new CatalogSubmissionScreenshotApplier($screenshotStorage, $mediaRepo, $root);
        $submissionEditor = new CatalogSubmissionEditor($submissions, $github, $publisher, $screenshotApplier);
        $pluginRepo = new PluginRepository($pdo);
        $migrationRunner = new PluginMigrationRunner($pdo);

        $authMw = new RequireCmsStaff($auth, $pdo);
        $permMw = new RequirePermission($pdo, [PermissionSlug::MANAGE_PLUGINS]);

        $adminView = static function (Request $request, array $extra = []) use ($ctx): array {
            /** @var array<string, mixed> $cmsUser */
            $cmsUser = $request->getAttribute('cms_user') ?? [];

            return array_merge($ctx->viewData([
                'admin_nav' => 'extensions_plugins',
                'cms_user' => $cmsUser,
            ]), $extra);
        };

        $catalogMediaPickerContext = static function (Request $request) use ($mediaRepo): array {
            /** @var array<string, mixed> $cmsUser */
            $cmsUser = $request->getAttribute('cms_user') ?? [];
            $slugs = $cmsUser['permission_slugs'] ?? [];
            $enabled = is_array($slugs) && in_array(PermissionSlug::MANAGE_MEDIA, $slugs, true);
            $picker = [];
            if ($enabled) {
                foreach ($mediaRepo->listImagesForPicker(240) as $row) {
                    if (($row['public_url'] ?? '') === '') {
                        continue;
                    }
                    $picker[] = [
                        'id' => $row['id'],
                        'url' => $row['public_url'],
                        'name' => $row['original_name'],
                    ];
                }
            }

            return [
                'media_picker_enabled' => $enabled,
                'media_picker_initial' => $picker,
                'media_picker_max_mb' => (int) round(MediaUploadService::maxBytesFromEnv() / 1024 / 1024),
            ];
        };

        $cmsUid = static function (Request $request): ?int {
            /** @var array<string, mixed> $u */
            $u = $request->getAttribute('cms_user') ?? [];
            $id = isset($u['id']) ? (int) $u['id'] : 0;

            return $id > 0 ? $id : null;
        };

        $app->group('/admin', function (\Slim\Routing\RouteCollectorProxy $group) use (
            $twig,
            $adminView,
            $submissions,
            $publisher,
            $settings,
            $cmsUid,
            $ns,
            $permMw,
            $authMw,
            $pdo,
            $submissionEditor,
            $scanner,
            $pluginRepo,
            $migrationRunner,
            $root
        ): void {
            $group->get('/extensions/struxa-catalog/submissions', function (Request $request, Response $response) use (
                $twig,
                $adminView,
                $submissions,
                $settings,
                $ns,
                $root
            ): Response {
                $q = $request->getQueryParams();
                $status = isset($q['status']) ? trim((string) $q['status']) : SubmissionStatus::PENDING;
                $kind = isset($q['kind']) ? trim((string) $q['kind']) : '';
                $distRoot = $settings->distRoot();
                $diskVer = StruxaCatalogStackShipper::diskVersion($root);
                $repoVer = StruxaCatalogStackShipper::repoVersion(StruxaCatalogStackShipper::resolveDistRoot($root));

                return $twig->render($response, $ns . '/admin/submissions.twig', $adminView($request, [
                    'submission_rows' => $submissions->listByStatus(
                        $status !== '' ? $status : null,
                        $kind !== '' ? $kind : null,
                        100
                    ),
                    'filter_status' => $status,
                    'filter_kind' => $kind,
                    'pending_count' => $submissions->countPending(),
                    'approved_count' => $submissions->countByStatus(SubmissionStatus::APPROVED),
                    'rejected_count' => $submissions->countByStatus(SubmissionStatus::REJECTED),
                    'submission_total' => $submissions->countAll(),
                    'dist_root' => $distRoot,
                    'catalog_admin_disk_version' => $diskVer,
                    'catalog_admin_repo_version' => $repoVer,
                    'catalog_repo_public_url' => rtrim($settings->catalogPublicBaseUrl(), '/') . '/struxa-dist/repo.json',
                    'php_zip_available' => ZipExtension::isAvailable(),
                    'php_zip_diagnostics' => ZipExtension::diagnostics(),
                ]));
            })->setName(self::ROUTE_SUBMISSIONS);

            $group->post('/extensions/struxa-catalog/ship-stack', function (Request $request, Response $response) use (
                $root,
                $pdo,
                $scanner,
                $pluginRepo,
                $migrationRunner,
                $cmsUid
            ): Response {
                $parser = RouteContext::fromRequest($request)->getRouteParser();
                $back = $parser->urlFor(self::ROUTE_SUBMISSIONS, [], ['status' => 'approved', 'kind' => '']);
                $body = $request->getParsedBody();
                $body = is_array($body) ? $body : [];
                $token = isset($body['_csrf_token']) && is_string($body['_csrf_token']) ? $body['_csrf_token'] : '';
                if (!CsrfToken::validate($token)) {
                    Flash::set('error', 'Invalid security token. Please try again.');

                    return $response->withHeader('Location', $back)->withStatus(302);
                }

                $shipper = new StruxaCatalogStackShipper($root, $pdo, $scanner, $pluginRepo, $migrationRunner);
                $result = $shipper->ship();
                if (!$result['ok']) {
                    Flash::set('error', $result['error']);

                    return $response->withHeader('Location', $back)->withStatus(302);
                }

                $msg = implode(' ', $result['messages']);
                if (!empty($result['reload_recommended'])) {
                    $msg .= ' Reload this page (and Plugins) so the upgraded catalog admin code is active.';
                }
                Flash::set('success', $msg);
                Events::dispatch(new StorefrontCachesInvalidateEvent('catalog_stack_ship'));

                return $response->withHeader('Location', $back)->withStatus(302);
            })->setName('admin.struxa_catalog.ship_stack');

            $group->get('/extensions/struxa-catalog/submissions/{id:[0-9]+}', function (
                Request $request,
                Response $response,
                array $args
            ) use ($twig, $adminView, $submissions, $settings, $ns, $pdo, $catalogMediaPickerContext): Response {
                $id = (int) $args['id'];
                $row = $submissions->findById($id);
                if ($row === null) {
                    throw new HttpNotFoundException($request);
                }
                $downloadCount = (new \StruxaAdmin\CatalogDownloadStatsRepository($pdo))
                    ->countFor($row->kind, $row->slug);
                $parser = RouteContext::fromRequest($request)->getRouteParser();
                $screenshotPreview = '';
                if ($row->screenshotPath !== null && $row->screenshotPath !== '') {
                    $screenshotPreview = $parser->urlFor(
                        'public.struxa_catalog.screenshot',
                        ['file' => basename($row->screenshotPath)],
                    );
                }

                return $twig->render($response, $ns . '/admin/submission_show.twig', $adminView($request, array_merge(
                    $catalogMediaPickerContext($request),
                    [
                        'submission' => $row,
                        'dist_root' => $settings->distRoot(),
                        'zip_base_url' => $settings->zipBaseUrl(),
                        'download_count' => $downloadCount,
                        'catalog_screenshot_preview_url' => $screenshotPreview,
                        'member_search_url' => $parser->urlFor('admin.struxa_catalog.member_search'),
                        'php_zip_available' => ZipExtension::isAvailable(),
                        'php_zip_diagnostics' => ZipExtension::diagnostics(),
                    ],
                )));
            })->setName('admin.struxa_catalog.submission_show');

            $group->get('/extensions/struxa-catalog/members/search', function (
                Request $request,
                Response $response
            ) use ($pdo): Response {
                $q = trim((string) ($request->getQueryParams()['q'] ?? ''));
                $members = CatalogMemberLookup::search($pdo, $q);
                $payload = json_encode(['members' => $members], JSON_THROW_ON_ERROR);

                return $response
                    ->withHeader('Content-Type', 'application/json; charset=utf-8')
                    ->withHeader('Cache-Control', 'private, no-store')
                    ->withBody((static function (string $json) {
                        $stream = fopen('php://temp', 'r+');
                        if ($stream === false) {
                            throw new \RuntimeException('Could not open temp stream.');
                        }
                        fwrite($stream, $json);
                        rewind($stream);

                        return new \Slim\Psr7\Stream($stream);
                    })($payload));
            })->setName('admin.struxa_catalog.member_search');

            $group->post('/extensions/struxa-catalog/submissions/{id:[0-9]+}/update', function (
                Request $request,
                Response $response,
                array $args
            ) use ($submissions, $submissionEditor, $pdo): Response {
                $id = (int) $args['id'];
                $row = $submissions->findById($id);
                $parser = RouteContext::fromRequest($request)->getRouteParser();
                $back = $parser->urlFor('admin.struxa_catalog.submission_show', ['id' => (string) $id]);
                if ($row === null) {
                    throw new HttpNotFoundException($request);
                }
                $body = $request->getParsedBody();
                $body = is_array($body) ? $body : [];
                $result = $submissionEditor->update($pdo, $id, $body);
                if (!$result['ok']) {
                    Flash::set('error', implode(' ', $result['errors']));

                    return $response->withHeader('Location', $back . '#edit')->withStatus(302);
                }
                $msg = !empty($result['regenerated'])
                    ? 'Submission updated and live catalog regenerated.'
                    : 'Submission updated.';
                Flash::set('success', $msg);

                return $response->withHeader('Location', $back . '#edit')->withStatus(302);
            })->setName('admin.struxa_catalog.submission_update');

            $group->post('/extensions/struxa-catalog/submissions/{id:[0-9]+}/review', function (
                Request $request,
                Response $response,
                array $args
            ) use ($submissions, $publisher, $cmsUid): Response {
                $id = (int) $args['id'];
                $row = $submissions->findById($id);
                $parser = RouteContext::fromRequest($request)->getRouteParser();
                $back = $parser->urlFor('admin.struxa_catalog.submission_show', ['id' => (string) $id]);
                if ($row === null) {
                    throw new HttpNotFoundException($request);
                }
                $body = $request->getParsedBody();
                $body = is_array($body) ? $body : [];
                $action = trim((string) ($body['action'] ?? ''));
                $notes = trim((string) ($body['reviewer_notes'] ?? ''));

                if ($action === 'approve') {
                    $pub = $publisher->approveAndPublish($row);
                    if (!$pub['ok']) {
                        Flash::set('error', 'Approval failed: ' . $pub['error']);

                        return $response->withHeader('Location', $back)->withStatus(302);
                    }
                    $submissions->setStatus($id, SubmissionStatus::APPROVED, $notes, $cmsUid($request), gmdate('Y-m-d H:i:s'));
                    Flash::set('success', 'Approved and published to the distribution catalog (repo.json + ZIP).');
                } elseif ($action === 'reject') {
                    $submissions->setStatus($id, SubmissionStatus::REJECTED, $notes, $cmsUid($request));
                    Flash::set('success', 'Submission rejected.');
                } else {
                    Flash::set('error', 'Unknown action.');
                }

                return $response->withHeader('Location', $back)->withStatus(302);
            })->setName('admin.struxa_catalog.submission_review');

            $group->get('/extensions/struxa-catalog/settings', function (Request $request, Response $response) use (
                $twig,
                $adminView,
                $settings,
                $ns
            ): Response {
                $bundledThemeVersion = null;
                $themeDir = self::cmsProjectRoot() . '/themes/struxa-theme';
                $manifest = \App\Theme\ThemeManifest::tryLoadRelaxedPath($themeDir);
                if ($manifest !== null) {
                    $bundledThemeVersion = $manifest->version;
                }

                return $twig->render($response, $ns . '/admin/settings.twig', $adminView($request, [
                    'dist_root' => $settings->distRoot(),
                    'zip_base_url' => $settings->zipBaseUrl(),
                    'screenshot_base_url' => $settings->screenshotPublicBaseUrl(),
                    'has_github_token' => $settings->githubToken() !== null,
                    'bundled_theme_version' => $bundledThemeVersion,
                    'catalog_repo_url' => rtrim($settings->catalogPublicBaseUrl(), '/') . '/struxa-dist/repo.json',
                    'php_zip_available' => ZipExtension::isAvailable(),
                    'php_zip_diagnostics' => ZipExtension::diagnostics(),
                    'php_zip_dist_zips_dir' => $settings->distRoot() . '/zips',
                    'php_zip_dist_zips_writable' => ZipExtension::probeWritableDirectory($settings->distRoot() . '/zips'),
                ]));
            })->setName('admin.struxa_catalog.settings');

            $group->post('/extensions/struxa-catalog/settings', function (Request $request, Response $response) use (
                $settings,
                $publisher,
                $submissions,
                $cmsUid
            ): Response {
                $parser = RouteContext::fromRequest($request)->getRouteParser();
                $back = $parser->urlFor('admin.struxa_catalog.settings');
                $body = $request->getParsedBody();
                $body = is_array($body) ? $body : [];
                $action = trim((string) ($body['action'] ?? 'save'));

                if ($action === 'publish_bundled_theme') {
                    $pub = $publisher->publishBundledStruxaThemeToCatalog();
                    if ($pub['ok']) {
                        $ver = trim((string) ($pub['version'] ?? ''));
                        $msg = $ver !== ''
                            ? 'Published bundled Struxa Vision v' . $ver . ' to repo.json and struxa-theme.zip.'
                            : 'Published bundled Struxa Vision to repo.json and struxa-theme.zip.';
                        $msg .= ' Themes → Reinstall from catalog to apply on this site.';
                        Flash::set('success', $msg);
                    } else {
                        Flash::set('error', $pub['error'] ?? 'Publish failed.');
                    }

                    return $response->withHeader('Location', $back)->withStatus(302);
                }

                if ($action === 'regenerate') {
                    $regen = $publisher->regenerateCatalog();
                    if ($regen['ok']) {
                        $msg = 'Catalog repo.json regenerated from approved submissions.';
                        $synced = $regen['synced_bundled'] ?? [];
                        if (is_array($synced) && $synced !== []) {
                            $msg .= ' Refreshed bundled packages: ' . implode(', ', $synced) . '.';
                        }
                        Flash::set('success', $msg);
                    } else {
                        Flash::set('error', $regen['error'] ?? 'Regenerate failed.');
                    }

                    return $response->withHeader('Location', $back)->withStatus(302);
                }

                if ($action === 'import_repo') {
                    $importer = new CatalogRepoJsonImporter($settings, $submissions, $publisher);
                    $updateExisting = !empty($body['update_existing']);
                    $result = $importer->importFromDistRepoJson($cmsUid($request), $updateExisting);
                    if (!$result['ok']) {
                        Flash::set('error', 'Import failed: ' . $result['error']);
                    } else {
                        $msg = sprintf(
                            'Imported %d, updated %d, skipped %d from repo.json.',
                            $result['imported'],
                            $result['updated'],
                            $result['skipped']
                        );
                        Flash::set('success', $msg);
                    }

                    return $response->withHeader('Location', $back)->withStatus(302);
                }

                $settings->save([
                    CatalogSettings::KEY_DIST_ROOT => trim((string) ($body['dist_root'] ?? '')),
                    CatalogSettings::KEY_ZIP_BASE_URL => trim((string) ($body['zip_base_url'] ?? '')),
                    CatalogSettings::KEY_SCREENSHOT_BASE_URL => trim((string) ($body['screenshot_base_url'] ?? '')),
                    CatalogSettings::KEY_GITHUB_TOKEN => trim((string) ($body['github_token'] ?? '')),
                ]);
                Flash::set('success', 'Catalog settings saved.');

                return $response->withHeader('Location', $back)->withStatus(302);
            })->setName('admin.struxa_catalog.settings.save');
        })->add($permMw)->add($authMw);
    }

    public static function namedRouteExists(App $app, string $name): bool
    {
        foreach ($app->getRouteCollector()->getRoutes() as $route) {
            if ($route->getName() === $name) {
                return true;
            }
        }

        return false;
    }
}
