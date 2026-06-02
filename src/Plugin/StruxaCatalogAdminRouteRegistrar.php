<?php

declare(strict_types=1);

namespace App\Plugin;

use App\Access\PermissionSlug;
use App\Event\EventDispatcher;
use App\Flash;
use App\Http\Middleware\RequireCmsStaff;
use App\Http\Middleware\RequirePermission;
use PHPAuth\Auth;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Exception\HttpNotFoundException;
use Slim\Routing\RouteContext;
use Slim\Views\Twig;
use StruxaAdmin\CatalogPublisher;
use StruxaAdmin\CatalogRepoJsonImporter;
use StruxaAdmin\CatalogSettings;
use StruxaAdmin\CatalogSubmissionRepository;
use StruxaAdmin\GitHubRepoClient;
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
            $authMw
        ): void {
            $group->get('/extensions/struxa-catalog/submissions', function (Request $request, Response $response) use (
                $twig,
                $adminView,
                $submissions,
                $settings,
                $ns
            ): Response {
                $q = $request->getQueryParams();
                $status = isset($q['status']) ? trim((string) $q['status']) : SubmissionStatus::PENDING;
                $kind = isset($q['kind']) ? trim((string) $q['kind']) : '';

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
                    'dist_root' => $settings->distRoot(),
                ]));
            })->setName(self::ROUTE_SUBMISSIONS);

            $group->get('/extensions/struxa-catalog/submissions/{id:[0-9]+}', function (
                Request $request,
                Response $response,
                array $args
            ) use ($twig, $adminView, $submissions, $settings, $ns): Response {
                $id = (int) $args['id'];
                $row = $submissions->findById($id);
                if ($row === null) {
                    throw new HttpNotFoundException($request);
                }

                return $twig->render($response, $ns . '/admin/submission_show.twig', $adminView($request, [
                    'submission' => $row,
                    'dist_root' => $settings->distRoot(),
                ]));
            })->setName('admin.struxa_catalog.submission_show');

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
                return $twig->render($response, $ns . '/admin/settings.twig', $adminView($request, [
                    'dist_root' => $settings->distRoot(),
                    'zip_base_url' => $settings->zipBaseUrl(),
                    'screenshot_base_url' => $settings->screenshotPublicBaseUrl(),
                    'has_github_token' => $settings->githubToken() !== null,
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

                if ($action === 'regenerate') {
                    $regen = $publisher->regenerateCatalog();
                    Flash::set($regen['ok'] ? 'success' : 'error', $regen['ok'] ? 'Catalog repo.json regenerated from approved submissions.' : $regen['error']);

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
