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
use Slim\Exception\HttpNotFoundException;
use App\Http\Middleware\RequirePermission;
use App\Access\RoleRepository;
use App\Commerce\Coupon\CouponRepository;
use App\Commerce\Shipping\ShippingZoneRepository;
use App\Commerce\Tax\TaxRateRepository;
use App\Config\ConfigDiffService;
use App\Config\ConfigExtendedImporter;
use App\Config\ConfigPackageRegistry;
use App\Config\ConfigPackageService;
use App\Config\ConfigPackageStore;
use App\Config\ConfigStructureExporter;
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
    $configExporter = new ConfigStructureExporter($collector, $pdo);
    $configStore = new ConfigPackageStore($root);
    $configPackages = new ConfigPackageService(
        $configExporter,
        $importExport,
        new ConfigDiffService(),
        new ConfigExtendedImporter(
            $pdo,
            $settingsRepo,
            new RoleRepository($pdo),
            new ShippingZoneRepository($pdo),
            new TaxRateRepository($pdo),
            new CouponRepository($pdo),
        ),
        $configStore,
    );
    $activity = new ActivityLogger($pdo);
    $publicApiKeys = new PublicApiKeyRepository($pdo);

    $parseConfigJson = static function (Request $request): array {
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $raw = '';
        if (!empty($_FILES['json_file']['tmp_name']) && is_uploaded_file((string) $_FILES['json_file']['tmp_name'])) {
            $t = file_get_contents((string) $_FILES['json_file']['tmp_name']);
            $raw = $t !== false ? $t : '';
        }
        if ($raw === '' && isset($body['json_text']) && is_string($body['json_text'])) {
            $raw = trim($body['json_text']);
        }
        if ($raw === '') {
            throw new \InvalidArgumentException('Provide a JSON file or paste JSON.');
        }
        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('Invalid JSON: ' . $e->getMessage(), 0, $e);
        }
        if (!is_array($data)) {
            throw new \InvalidArgumentException('JSON root must be an object.');
        }

        return $data;
    };

    $app->group('/admin', function (\Slim\Routing\RouteCollectorProxy $group) use (
        $twig,
        $adminContext,
        $withCmsUser,
        $blueprintManager,
        $importExport,
        $configPackages,
        $configStore,
        $profileRepo,
        $activity,
        $cmsUid,
        $pdo,
        $publicApiKeys,
        $parseConfigJson
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

        // Guardrail: visiting the POST endpoint directly should bounce back cleanly.
        $group->get('/tools/blueprints/import', function (Request $request, Response $response): Response {
            Flash::set('error', 'Use the Blueprints form to import (POST).');

            return $response
                ->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.tools.blueprints'))
                ->withStatus(302);
        });

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
            $allowed = ImportExportService::SCOPES;
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
            $allowed = ImportExportService::SCOPES;
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

        $group->get('/tools/config-sync', function (Request $request, Response $response) use (
            $twig,
            $adminContext,
            $withCmsUser,
            $configStore,
            $profileRepo
        ): Response {
            $profile = $profileRepo->get();

            return $twig->render($response, 'admin/tools/config_sync.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'tools_config_sync',
                'config_packages' => ConfigPackageRegistry::builtIn(),
                'saved_packages' => $configStore->listSaved(),
                'all_scopes' => ConfigPackageRegistry::ALL_SCOPES,
                'site_profile' => $profile,
            ])));
        })->setName('admin.tools.config_sync');

        $group->post('/tools/config-sync/export', function (Request $request, Response $response) use (
            $configPackages,
            $configStore,
            $profileRepo,
            $activity,
            $cmsUid
        ): Response {
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $packageId = trim((string) ($body['package_id'] ?? 'agency-staging'));
            $label = trim((string) ($body['label'] ?? ''));
            if ($label === '') {
                $pkg = ConfigPackageRegistry::findBuiltIn($packageId);
                $label = $pkg !== null ? $pkg->label : 'Config package';
            }
            $profile = $profileRepo->get();
            $sourceEnv = trim((string) ($body['source_environment'] ?? ''));
            if ($sourceEnv === '') {
                $sourceEnv = trim((string) ($profile['environment_label'] ?? ''));
            }
            $scopes = isset($body['scopes']) && is_array($body['scopes']) ? $body['scopes'] : null;
            $includeEntries = !empty($body['include_entries']);
            $document = $configPackages->exportDocument($packageId, $scopes, $includeEntries, $label, $sourceEnv !== '' ? $sourceEnv : null);
            $activity->log($cmsUid($request), 'config_package.exported', null, null, [
                'package_id' => $packageId,
                'scopes' => $document['scopes'] ?? [],
            ]);

            if (!empty($body['save_to_storage'])) {
                $basename = trim((string) ($body['basename'] ?? ''));
                if ($basename === '') {
                    $basename = $packageId . '-' . gmdate('Ymd-His');
                }
                $configStore->save($basename, $document);
                Flash::set('success', 'Config package saved to storage/config-packages/' . $basename . '.json');

                return $response
                    ->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.tools.config_sync'))
                    ->withStatus(302);
            }

            $json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $fn = preg_replace('/[^a-z0-9\-]+/i', '-', strtolower($packageId)) ?: 'config-package';
            $response = $response
                ->withHeader('Content-Type', 'application/json; charset=utf-8')
                ->withHeader('Content-Disposition', 'attachment; filename="' . $fn . '.json"');
            $response->getBody()->write($json);

            return $response;
        })->setName('admin.tools.config_sync.export');

        $group->post('/tools/config-sync/preview', function (Request $request, Response $response) use (
            $configPackages,
            $configStore,
            $parseConfigJson
        ): Response {
            $parser = RouteContext::fromRequest($request)->getRouteParser();
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            try {
                $basename = trim((string) ($body['saved_basename'] ?? ''));
                if ($basename !== '') {
                    $document = $configStore->loadFile($basename);
                } else {
                    $document = $parseConfigJson($request);
                }
            } catch (\Throwable $e) {
                Flash::set('error', $e->getMessage());

                return $response
                    ->withHeader('Location', $parser->urlFor('admin.tools.config_sync'))
                    ->withStatus(302);
            }
            $scopes = isset($body['scopes']) && is_array($body['scopes']) ? $body['scopes'] : null;
            $opt = new BlueprintImportOptions(
                dryRun: true,
                merge: (string) ($body['merge'] ?? '1') !== '0',
                applyThemeFromBlueprint: !empty($body['apply_theme']),
                importContentEntries: !empty($body['import_entries']),
            );
            try {
                $preview = $configPackages->preview($document, $scopes, $opt);
            } catch (\Throwable $e) {
                Flash::set('error', $e->getMessage());

                return $response
                    ->withHeader('Location', $parser->urlFor('admin.tools.config_sync'))
                    ->withStatus(302);
            }
            $token = $configStore->storeInbox([
                'document' => $document,
                'preview' => $preview,
                'import_options' => [
                    'merge' => $opt->merge,
                    'apply_theme' => $opt->applyThemeFromBlueprint,
                    'import_entries' => $opt->importContentEntries,
                ],
                'scopes' => $preview['scopes'],
            ]);

            return $response
                ->withHeader('Location', $parser->urlFor('admin.tools.config_sync.preview', [], ['token' => $token]))
                ->withStatus(302);
        })->setName('admin.tools.config_sync.preview');

        $group->get('/tools/config-sync/preview', function (Request $request, Response $response) use (
            $twig,
            $adminContext,
            $withCmsUser,
            $configStore
        ): Response {
            $token = trim((string) ($request->getQueryParams()['token'] ?? ''));
            if ($token === '') {
                throw new HttpNotFoundException($request);
            }
            try {
                $staged = $configStore->loadInbox($token);
            } catch (\Throwable) {
                throw new HttpNotFoundException($request);
            }
            $preview = is_array($staged['preview'] ?? null) ? $staged['preview'] : [];

            return $twig->render($response, 'admin/tools/config_sync_preview.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'tools_config_sync',
                'preview_token' => $token,
                'preview_label' => (string) ($preview['label'] ?? 'Config import'),
                'preview_package_id' => (string) ($preview['package_id'] ?? ''),
                'preview_scopes' => $preview['scopes'] ?? [],
                'diff' => $preview['diff'] ?? ['summary' => [], 'sections' => []],
                'import_result' => $preview['import'] ?? ['errors' => [], 'warnings' => [], 'applied' => []],
                'import_options' => $staged['import_options'] ?? [],
            ])));
        })->setName('admin.tools.config_sync.preview');

        $group->post('/tools/config-sync/apply', function (Request $request, Response $response) use (
            $configPackages,
            $configStore,
            $activity,
            $cmsUid,
            $pdo
        ): Response {
            $parser = RouteContext::fromRequest($request)->getRouteParser();
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $token = trim((string) ($body['preview_token'] ?? ''));
            if ($token === '') {
                Flash::set('error', 'Missing preview session.');

                return $response->withHeader('Location', $parser->urlFor('admin.tools.config_sync'))->withStatus(302);
            }
            try {
                $staged = $configStore->loadInbox($token);
            } catch (\Throwable $e) {
                Flash::set('error', $e->getMessage());

                return $response->withHeader('Location', $parser->urlFor('admin.tools.config_sync'))->withStatus(302);
            }
            $document = is_array($staged['document'] ?? null) ? $staged['document'] : [];
            $scopes = is_array($staged['scopes'] ?? null) ? $staged['scopes'] : [];
            $io = is_array($staged['import_options'] ?? null) ? $staged['import_options'] : [];
            try {
                $unwrapped = $configPackages->unwrap($document);
            } catch (\Throwable $e) {
                Flash::set('error', $e->getMessage());

                return $response->withHeader('Location', $parser->urlFor('admin.tools.config_sync'))->withStatus(302);
            }
            $opt = new BlueprintImportOptions(
                dryRun: false,
                merge: !empty($io['merge']),
                applyThemeFromBlueprint: !empty($io['apply_theme']),
                importContentEntries: !empty($io['import_entries']),
            );
            $result = $configPackages->import($unwrapped['structure'], $scopes !== [] ? $scopes : $unwrapped['scopes'], $opt);
            $configStore->deleteInbox($token);
            if ($result['errors'] !== []) {
                Flash::set('error', implode(' ', $result['errors']));
            } else {
                Flash::set('success', implode(' · ', array_merge($result['applied'], $result['warnings'])));
                $activity->log($cmsUid($request), 'config_package.imported', null, null, ['scopes' => $scopes]);
                Settings::reload($pdo);
                Events::dispatch(new StorefrontCachesInvalidateEvent('config_package_import'));
            }

            return $response
                ->withHeader('Location', $parser->urlFor('admin.tools.config_sync'))
                ->withStatus(302);
        })->setName('admin.tools.config_sync.apply');

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
