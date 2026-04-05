<?php

declare(strict_types=1);

use App\Access\ActivityLogger;
use App\Access\PermissionSlug;
use App\Api\PublicApiKeyRepository;
use App\Blueprint\BlueprintImportOptions;
use App\Blueprint\BlueprintManager;
use App\Blueprint\StructureCollector;
use App\CmsVersion;
use App\Content\ContentEntryRepository;
use App\Content\ContentEntryValueRepository;
use App\Content\ContentFieldRepository;
use App\Content\ContentTypeRepository;
use App\Event\Events;
use App\Event\StorefrontCachesInvalidateEvent;
use App\Flash;
use App\Http\Middleware\RequireCmsStaff;
use App\Http\Middleware\RequirePermission;
use App\ImportExport\ImportExportService;
use App\Menu\MenuItemRepository;
use App\Menu\MenuRepository;
use App\Page\PageRepository;
use App\Section\PageSectionRepository;
use App\Section\SectionManager;
use App\Seo\RedirectRepository;
use App\Section\SectionSchemaValidator;
use App\Plugin\PluginRepository;
use App\Settings\SettingsRepository;
use App\SiteProfile\SiteProfileRepository;
use App\Settings;
use App\Taxonomy\ContentEntryTaxonomyRepository;
use App\Taxonomy\TaxonomyRepository;
use App\Taxonomy\TaxonomyTermRepository;
use App\Theme\ThemeManager;
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
    $perm = new RequirePermission($pdo, [PermissionSlug::MANAGE_PORTABILITY]);
    $root = dirname(__DIR__);

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

    $types = new ContentTypeRepository($pdo);
    $fields = new ContentFieldRepository($pdo);
    $tax = new TaxonomyRepository($pdo);
    $terms = new TaxonomyTermRepository($pdo);
    $menus = new MenuRepository($pdo);
    $menuItems = new MenuItemRepository($pdo);
    $settingsRepo = new SettingsRepository($pdo);
    $profileRepo = new SiteProfileRepository($pdo);
    $themes = new ThemeManager($root);
    $plugins = new PluginRepository($pdo);
    $entries = new ContentEntryRepository($pdo);
    $entryValues = new ContentEntryValueRepository($pdo);
    $pages = new PageRepository($pdo);
    $pageSections = new PageSectionRepository($pdo);
    $entryTaxonomy = new ContentEntryTaxonomyRepository($pdo);
    $sectionSchema = new SectionSchemaValidator(new SectionManager());

    $redirectRepo = new RedirectRepository($pdo);
    $collector = new StructureCollector(
        $types,
        $fields,
        $tax,
        $terms,
        $menus,
        $menuItems,
        $settingsRepo,
        $profileRepo,
        $themes,
        $plugins,
        $entries,
        $entryValues,
        $pages,
        $pageSections,
        $redirectRepo
    );
    $blueprintManager = new BlueprintManager(
        $pdo,
        $root,
        $types,
        $fields,
        $tax,
        $terms,
        $menus,
        $menuItems,
        $settingsRepo,
        $profileRepo,
        $themes,
        $entries,
        $entryValues,
        $pages,
        $pageSections,
        $sectionSchema,
        $entryTaxonomy,
        $collector
    );
    $importExport = new ImportExportService($collector, $blueprintManager);
    $activity = new ActivityLogger($pdo);
    $publicApiKeys = new PublicApiKeyRepository($pdo);

    $app->group('/admin', function (\Slim\Routing\RouteCollectorProxy $group) use (
        $twig,
        $adminContext,
        $withCmsUser,
        $blueprintManager,
        $importExport,
        $profileRepo,
        $activity,
        $cmsUid,
        $pdo,
        $publicApiKeys
    ): void {
        $group->get('/tools/blueprints', function (Request $request, Response $response) use (
            $twig,
            $adminContext,
            $withCmsUser,
            $blueprintManager,
            $profileRepo
        ): Response {
            $profileRepo->syncInstalledVersion();

            return $twig->render($response, 'admin/tools/blueprints.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'tools_blueprints',
                'blueprint_files' => $blueprintManager->listStoredBlueprints(),
                'site_profile' => $profileRepo->get(),
                'cms_version' => CmsVersion::CURRENT,
            ])));
        })->setName('admin.tools.blueprints');

        $group->post('/tools/site-profile', function (Request $request, Response $response) use ($profileRepo): Response {
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $profileRepo->update([
                'project_name' => trim((string) ($body['project_name'] ?? '')),
                'environment_label' => trim((string) ($body['environment_label'] ?? '')),
            ]);
            Flash::set('success', 'Site profile updated.');
            Events::dispatch(new StorefrontCachesInvalidateEvent('site_profile'));

            return $response
                ->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.tools.blueprints'))
                ->withStatus(302);
        })->setName('admin.tools.site_profile');

        $group->post('/tools/blueprints/export-download', function (Request $request, Response $response) use (
            $blueprintManager,
            $activity,
            $cmsUid
        ): Response {
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $label = trim((string) ($body['label'] ?? 'Site blueprint'));
            if ($label === '') {
                $label = 'Site blueprint';
            }
            $include = !empty($body['include_entries']);
            $payload = $blueprintManager->exportCurrentAsBlueprint($label, $include, 100);
            $activity->log($cmsUid($request), 'blueprint.exported', null, null, ['label' => $label]);
            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $fn = preg_replace('/[^a-z0-9\-]+/i', '-', strtolower($label));
            $fn = trim($fn, '-') ?: 'blueprint';

            $response = $response
                ->withHeader('Content-Type', 'application/json; charset=utf-8')
                ->withHeader('Content-Disposition', 'attachment; filename="' . $fn . '.json"');
            $response->getBody()->write($json);

            return $response;
        })->setName('admin.tools.blueprints.export_download');

        $group->post('/tools/blueprints/save', function (Request $request, Response $response) use (
            $blueprintManager,
            $activity,
            $cmsUid
        ): Response {
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $label = trim((string) ($body['label'] ?? 'saved-blueprint'));
            $basename = trim((string) ($body['basename'] ?? ''));
            if ($basename === '') {
                $basename = preg_replace('/[^a-z0-9\-]+/', '-', strtolower($label));
                $basename = trim($basename, '-') ?: 'blueprint';
            }
            try {
                $payload = $blueprintManager->exportCurrentAsBlueprint($label, !empty($body['include_entries']), 100);
                $blueprintManager->saveBlueprintFile($basename, $payload);
                $activity->log($cmsUid($request), 'blueprint.saved', null, null, ['file' => $basename . '.json']);
                Flash::set('success', 'Blueprint saved to storage/blueprints/' . $basename . '.json');
            } catch (\Throwable $e) {
                Flash::set('error', $e->getMessage());
            }

            return $response
                ->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.tools.blueprints'))
                ->withStatus(302);
        })->setName('admin.tools.blueprints.save');

        $group->post('/tools/blueprints/import', function (Request $request, Response $response) use (
            $blueprintManager,
            $activity,
            $cmsUid,
            $pdo
        ): Response {
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $raw = '';
            if (!empty($_FILES['json_file']['tmp_name']) && is_uploaded_file((string) $_FILES['json_file']['tmp_name'])) {
                $t = file_get_contents((string) $_FILES['json_file']['tmp_name']);
                $raw = $t !== false ? $t : '';
            }
            if ($raw === '' && isset($body['json_text']) && is_string($body['json_text'])) {
                $raw = $body['json_text'];
            }
            try {
                /** @var mixed $data */
                $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($data)) {
                    throw new \InvalidArgumentException('JSON root must be an object.');
                }
            } catch (\Throwable $e) {
                Flash::set('error', 'Invalid JSON: ' . $e->getMessage());

                return $response
                    ->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.tools.blueprints'))
                    ->withStatus(302);
            }
            $importMode = isset($body['import_mode']) ? (string) $body['import_mode'] : '';
            $dryRun = match ($importMode) {
                'apply' => false,
                'dry_run' => true,
                default => (string) ($body['dry_run'] ?? '0') === '1',
            };
            $opt = new BlueprintImportOptions(
                dryRun: $dryRun,
                merge: (string) ($body['merge'] ?? '1') !== '0',
                applyThemeFromBlueprint: !empty($body['apply_theme']),
                importContentEntries: !empty($body['import_entries'])
            );
            $result = $blueprintManager->importBlueprint($data, $opt);
            if ($result['errors'] !== []) {
                Flash::set('error', implode(' ', $result['errors']));
            } else {
                $msg = implode(' · ', array_merge($result['applied'], $result['warnings']));
                Flash::set('success', $msg !== '' ? $msg : 'Import finished.');
                if ($result['errors'] === [] && !$opt->dryRun) {
                    $activity->log($cmsUid($request), 'blueprint.imported', null, null, []);
                    Settings::reload($pdo);
                    Events::dispatch(new StorefrontCachesInvalidateEvent('blueprint_import'));
                }
            }

            return $response
                ->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.tools.blueprints'))
                ->withStatus(302);
        })->setName('admin.tools.blueprints.import');

        $group->get('/tools/import-export', function (Request $request, Response $response) use (
            $twig,
            $adminContext,
            $withCmsUser
        ): Response {
            return $twig->render($response, 'admin/tools/import_export.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'tools_import_export',
            ])));
        })->setName('admin.tools.import_export');

        $group->post('/tools/import-export/export', function (Request $request, Response $response) use ($importExport, $activity, $cmsUid): Response {
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $scopes = isset($body['scopes']) && is_array($body['scopes']) ? $body['scopes'] : [];
            $allowed = ['content_types', 'menus', 'settings', 'entries', 'meta'];
            $scopes = array_values(array_intersect($allowed, array_map('strval', $scopes)));
            if ($scopes === []) {
                $scopes = ['content_types', 'settings', 'menus', 'meta'];
            }
            $includeEntries = in_array('entries', $scopes, true);
            $payload = $importExport->exportJson($scopes, $includeEntries, 200);
            $activity->log($cmsUid($request), 'structure.exported', null, null, ['scopes' => $scopes]);
            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $response = $response
                ->withHeader('Content-Type', 'application/json; charset=utf-8')
                ->withHeader('Content-Disposition', 'attachment; filename="cms-structure-export.json"');
            $response->getBody()->write($json);

            return $response;
        })->setName('admin.tools.import_export.export');

        $group->post('/tools/import-export/import', function (Request $request, Response $response) use (
            $importExport,
            $activity,
            $cmsUid,
            $pdo
        ): Response {
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $raw = '';
            if (!empty($_FILES['json_file']['tmp_name']) && is_uploaded_file((string) $_FILES['json_file']['tmp_name'])) {
                $t = file_get_contents((string) $_FILES['json_file']['tmp_name']);
                $raw = $t !== false ? $t : '';
            }
            if ($raw === '' && isset($body['json_text']) && is_string($body['json_text'])) {
                $raw = $body['json_text'];
            }
            try {
                /** @var mixed $data */
                $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($data)) {
                    throw new \InvalidArgumentException('JSON root must be an object.');
                }
            } catch (\Throwable $e) {
                Flash::set('error', 'Invalid JSON: ' . $e->getMessage());

                return $response
                    ->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.tools.import_export'))
                    ->withStatus(302);
            }
            $scopes = isset($body['scopes']) && is_array($body['scopes']) ? $body['scopes'] : [];
            $allowed = ['content_types', 'menus', 'settings', 'entries', 'meta'];
            $scopes = array_values(array_intersect($allowed, array_map('strval', $scopes)));
            if ($scopes === []) {
                Flash::set('error', 'Select at least one import scope.');

                return $response
                    ->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.tools.import_export'))
                    ->withStatus(302);
            }
            $importMode = isset($body['import_mode']) ? (string) $body['import_mode'] : '';
            $dryRun = match ($importMode) {
                'apply' => false,
                'dry_run' => true,
                default => (string) ($body['dry_run'] ?? '0') === '1',
            };
            $opt = new BlueprintImportOptions(
                dryRun: $dryRun,
                merge: (string) ($body['merge'] ?? '1') !== '0',
                applyThemeFromBlueprint: in_array('meta', $scopes, true) && !empty($body['apply_theme']),
                importContentEntries: in_array('entries', $scopes, true) && !empty($body['import_entries'])
            );
            $result = $importExport->importJson($data, $scopes, $opt);
            if ($result['errors'] !== []) {
                Flash::set('error', implode(' ', $result['errors']));
            } else {
                Flash::set('success', implode(' · ', array_merge($result['applied'], $result['warnings'])));
                if (!$opt->dryRun) {
                    $activity->log($cmsUid($request), 'structure.imported', null, null, ['scopes' => $scopes]);
                    Settings::reload($pdo);
                    Events::dispatch(new StorefrontCachesInvalidateEvent('structure_import'));
                }
            }

            return $response
                ->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.tools.import_export'))
                ->withStatus(302);
        })->setName('admin.tools.import_export.import');

        $group->get('/tools/api-keys', function (Request $request, Response $response) use (
            $twig,
            $adminContext,
            $withCmsUser,
            $publicApiKeys
        ): Response {
            $rows = $publicApiKeys->listAll();
            $forView = [];
            foreach ($rows as $r) {
                $r['scopes_list'] = PublicApiKeyRepository::decodeScopesJson((string) ($r['scopes_json'] ?? '[]'));
                $forView[] = $r;
            }

            return $twig->render($response, 'admin/tools/api_keys.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'tools_api_keys',
                'api_keys' => $forView,
                'new_secret' => Flash::pull('api_key_secret'),
            ])));
        })->setName('admin.tools.api_keys');

        $group->post('/tools/api-keys', function (Request $request, Response $response) use (
            $publicApiKeys,
            $activity,
            $cmsUid
        ): Response {
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $name = trim((string) ($body['name'] ?? ''));
            $scopesRaw = isset($body['scopes']) && is_array($body['scopes']) ? $body['scopes'] : [];
            $scopes = PublicApiKeyRepository::normalizeScopes(array_map('strval', $scopesRaw));
            if (in_array('write', $scopes, true) || in_array('read_drafts', $scopes, true)) {
                $scopes[] = 'read';
                $scopes = PublicApiKeyRepository::normalizeScopes($scopes);
            }
            try {
                $out = $publicApiKeys->create($name, $scopes);
            } catch (\Throwable $e) {
                Flash::set('error', $e->getMessage());

                return $response
                    ->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.tools.api_keys'))
                    ->withStatus(302);
            }
            Flash::set('api_key_secret', $out['secret_once']);
            Flash::set('success', 'API key created. Copy the full secret below; it is only shown once.');
            $activity->log($cmsUid($request), 'public_api_key.created', null, null, ['prefix' => $out['prefix'], 'id' => $out['id']]);

            return $response
                ->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.tools.api_keys'))
                ->withStatus(302);
        })->setName('admin.tools.api_keys.create');

        $group->post('/tools/api-keys/{id:[0-9]+}/revoke', function (Request $request, Response $response, array $args) use (
            $publicApiKeys,
            $activity,
            $cmsUid
        ): Response {
            $id = (int) ($args['id'] ?? 0);
            $publicApiKeys->revoke($id);
            Flash::set('success', 'API key revoked.');
            $activity->log($cmsUid($request), 'public_api_key.revoked', null, null, ['id' => $id]);

            return $response
                ->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.tools.api_keys'))
                ->withStatus(302);
        })->setName('admin.tools.api_keys.revoke');
    })->add($perm)->add($middleware);
};
