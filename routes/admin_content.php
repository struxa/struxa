<?php

declare(strict_types=1);

use App\Admin\AfterSaveRedirect;
use App\Access\ActivityLogger;
use App\Access\PermissionSlug;
use App\Access\WorkflowService;
use App\Content\ContentEntry;
use App\Content\ContentEntryFormValidator;
use App\Content\ContentEntryRefsGuard;
use App\Content\ContentEntryRepository;
use App\Content\ContentEntryRevisionRepository;
use App\Content\ContentEntryValueRepository;
use App\Content\ContentFieldRepository;
use App\Content\ContentFieldValidator;
use App\Content\ContentSlugger;
use App\Content\ContentTypeRepository;
use App\Content\ContentTypeValidator;
use App\Event\ContentEntryDeletedEvent;
use App\Event\ContentEntrySavedEvent;
use App\Event\Events;
use App\Event\StorefrontCachesInvalidateEvent;
use App\Taxonomy\ContentEntryTaxonomyRepository;
use App\Taxonomy\EntryTaxonomySync;
use App\Taxonomy\EntryTaxonomyValidator;
use App\Taxonomy\TaxonomyRepository;
use App\Taxonomy\TaxonomyTermRepository;
use App\Taxonomy\TaxonomyTermTree;
use App\Flash;
use App\Preview\PreviewTokenRepository;
use App\Http\Middleware\RequireCmsStaff;
use App\Http\Middleware\RequirePermission;
use App\Media\MediaRepository;
use App\Media\MediaUploadService;
use App\Media\MediaUrlHelper;
use App\Menu\MenuItemRepository;
use App\Seo\RedirectRepository;
use App\Seo\SeoFormParser;
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
    $types = new ContentTypeRepository($pdo);
    $menuItems = new MenuItemRepository($pdo);
    $fields = new ContentFieldRepository($pdo);
    $entries = new ContentEntryRepository($pdo);
    $values = new ContentEntryValueRepository($pdo);
    $mediaRepo = new MediaRepository($pdo);
    $typeValidator = new ContentTypeValidator();
    $fieldValidator = new ContentFieldValidator();
    $entryValidator = new ContentEntryFormValidator();
    $taxonomyRepo = new TaxonomyRepository($pdo);
    $taxonomyTermRepo = new TaxonomyTermRepository($pdo);
    $entryTaxonomyRepo = new ContentEntryTaxonomyRepository($pdo);
    $entryTaxonomyValidator = new EntryTaxonomyValidator();
    $workflow = new WorkflowService();
    $entryRevRepo = new ContentEntryRevisionRepository($pdo);
    $activity = new ActivityLogger($pdo);
    $permDelete = new RequirePermission($pdo, [PermissionSlug::DELETE_CONTENT]);
    $permTypes = new RequirePermission($pdo, [PermissionSlug::MANAGE_CONTENT_TYPES]);
    $permEntryBrowse = new RequirePermission($pdo, [
        PermissionSlug::MANAGE_CONTENT_TYPES,
        PermissionSlug::CREATE_CONTENT,
        PermissionSlug::EDIT_CONTENT,
        PermissionSlug::REVIEW_CONTENT,
        PermissionSlug::PUBLISH_CONTENT,
    ]);
    $permEntryCreate = new RequirePermission($pdo, [PermissionSlug::CREATE_CONTENT]);
    $permEntryEdit = new RequirePermission($pdo, [PermissionSlug::EDIT_CONTENT, PermissionSlug::REVIEW_CONTENT]);

    $adminContext = static fn (): array => array_merge($viewData(), []);
    $withCmsUser = static function (Request $request, array $data): array {
        /** @var array<string, mixed> $cmsUser */
        $cmsUser = $request->getAttribute('cms_user') ?? [];

        return array_merge($data, ['cms_user' => $cmsUser]);
    };
    $cmsUserId = static function (Request $request): ?int {
        /** @var array<string, mixed> $u */
        $u = $request->getAttribute('cms_user') ?? [];
        $id = isset($u['id']) ? (int) $u['id'] : 0;

        return $id > 0 ? $id : null;
    };

    $mergeEntrySeoOld = static function (array $values, array $body): array {
        return array_merge($values, [
            'published_at' => trim((string) ($body['published_at'] ?? '')),
            'canonical_url' => trim((string) ($body['canonical_url'] ?? '')),
            'seo_noindex' => !empty($body['seo_noindex']),
            'og_title' => trim((string) ($body['og_title'] ?? '')),
            'og_description' => trim((string) ($body['og_description'] ?? '')),
            'og_image_id' => trim((string) ($body['og_image_id'] ?? '')),
            'twitter_title' => trim((string) ($body['twitter_title'] ?? '')),
            'twitter_description' => trim((string) ($body['twitter_description'] ?? '')),
            'twitter_image_id' => trim((string) ($body['twitter_image_id'] ?? '')),
            'schema_json' => (string) ($body['schema_json'] ?? ''),
            'scheduled_publish_at' => trim((string) ($body['scheduled_publish_at'] ?? '')),
            'scheduled_unpublish_at' => trim((string) ($body['scheduled_unpublish_at'] ?? '')),
        ]);
    };

    /** @var callable(Request, MediaRepository, \PDO, ?ContentEntry, ?array): array<string, mixed> */
    $entryFormMediaContext = static function (Request $request, MediaRepository $mediaRepo, \PDO $pdo, ?ContentEntry $entry, ?array $old): array {
        /** @var array<string, mixed> $cmsUser */
        $cmsUser = $request->getAttribute('cms_user') ?? [];
        $slugs = $cmsUser['permission_slugs'] ?? [];
        $enabled = is_array($slugs) && in_array(PermissionSlug::MANAGE_MEDIA, $slugs, true);
        $maxMb = (int) round(MediaUploadService::maxBytesFromEnv() / 1024 / 1024);
        $picker = [];
        if ($enabled) {
            foreach ($mediaRepo->listImagesForPicker(240) as $r) {
                if (($r['public_url'] ?? '') === '') {
                    continue;
                }
                $picker[] = [
                    'id' => $r['id'],
                    'url' => $r['public_url'],
                    'name' => $r['original_name'],
                ];
            }
        }
        $featuredUrl = '';
        $fid = null;
        if ($old !== null && array_key_exists('featured_image_id', $old)) {
            $raw = trim((string) $old['featured_image_id']);
            if ($raw !== '' && ctype_digit($raw)) {
                $fid = (int) $raw;
                $featuredUrl = (new MediaUrlHelper($pdo))->pathForId($fid);
            }
        } elseif ($entry !== null && $entry->featuredImageId !== null) {
            $fid = $entry->featuredImageId;
            $featuredUrl = (new MediaUrlHelper($pdo))->pathForId($fid);
        }

        return [
            'media_picker_enabled' => $enabled,
            'media_picker_initial' => $picker,
            'media_picker_max_mb' => $maxMb,
            'entry_edit_featured_url' => $featuredUrl,
        ];
    };

    $entryPrimaryRichtextTextareaId = static function (array $fieldList): ?string {
        foreach ($fieldList as $f) {
            if ($f->fieldType === 'richtext') {
                return 'cf-' . $f->id;
            }
        }

        return null;
    };

    $app->group('/admin', function (\Slim\Routing\RouteCollectorProxy $group) use (
        $twig,
        $adminContext,
        $withCmsUser,
        $cmsUserId,
        $types,
        $menuItems,
        $fields,
        $entries,
        $values,
        $mediaRepo,
        $typeValidator,
        $fieldValidator,
        $entryValidator,
        $taxonomyRepo,
        $taxonomyTermRepo,
        $entryTaxonomyRepo,
        $entryTaxonomyValidator,
        $workflow,
        $entryRevRepo,
        $activity,
        $permDelete,
        $permTypes,
        $permEntryBrowse,
        $permEntryCreate,
        $permEntryEdit,
        $pdo,
        $viewData,
        $mergeEntrySeoOld,
        $entryFormMediaContext,
        $entryPrimaryRichtextTextareaId
    ): void {
        $group->get('/content-types', function (Request $request, Response $response) use ($twig, $adminContext, $withCmsUser, $types, $entries, $fields): Response {
            $typeRows = $types->allOrdered();
            $entryStats = $entries->statsByContentType();
            $fieldCounts = $fields->countsByContentType();
            $cards = [];
            $summary = ['types' => count($typeRows), 'entries' => 0, 'published' => 0, 'draft' => 0];
            foreach ($typeRows as $t) {
                $stats = $entryStats[$t->id] ?? ['total' => 0, 'published' => 0, 'draft' => 0, 'in_review' => 0];
                $summary['entries'] += $stats['total'];
                $summary['published'] += $stats['published'];
                $summary['draft'] += $stats['draft'] + $stats['in_review'];
                $cards[] = [
                    'type' => $t,
                    'entry_total' => $stats['total'],
                    'entry_published' => $stats['published'],
                    'entry_draft' => $stats['draft'],
                    'entry_in_review' => $stats['in_review'],
                    'field_count' => $fieldCounts[$t->id] ?? 0,
                ];
            }

            return $twig->render($response, 'admin/content/types/index.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'content_types',
                'content_types' => $typeRows,
                'content_type_cards' => $cards,
                'content_types_summary' => $summary,
            ])));
        })->setName('admin.content_types.index')->add($permEntryBrowse);

        $group->get('/content/entries/new', function (Request $request, Response $response) use ($twig, $adminContext, $withCmsUser, $types): Response {
            return $twig->render($response, 'admin/content/entries/new_entry.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'content_types',
                'content_types' => $types->allOrdered(),
            ])));
        })->setName('admin.content.entries.new_picker')->add($permEntryCreate);

        $group->group('', function (\Slim\Routing\RouteCollectorProxy $g) use (
            $twig,
            $adminContext,
            $withCmsUser,
            $cmsUserId,
            $types,
            $menuItems,
            $fields,
            $entries,
            $values,
            $mediaRepo,
            $typeValidator,
            $fieldValidator,
            $entryValidator,
            $taxonomyRepo,
            $taxonomyTermRepo,
            $entryTaxonomyRepo,
            $entryTaxonomyValidator,
            $workflow,
            $entryRevRepo,
            $activity,
            $permDelete
        ): void {
        $g->get('/content-types/new', function (Request $request, Response $response) use ($twig, $adminContext, $withCmsUser): Response {
            return $twig->render($response, 'admin/content/types/form.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'content_types',
                'form_mode' => 'create',
                'content_type' => null,
                'errors' => [],
                'old' => [
                    'name' => '',
                    'slug' => '',
                    'icon' => '',
                    'description' => '',
                    'has_public_route' => false,
                    'supports_seo' => false,
                    'supports_featured_image' => false,
                ],
            ])));
        })->setName('admin.content_types.new');

        $g->post('/content-types/new', function (Request $request, Response $response) use ($twig, $adminContext, $withCmsUser, $types, $typeValidator): Response {
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $result = $typeValidator->validate($body, null, $types);
            if ($result['errors'] !== []) {
                return $twig->render($response, 'admin/content/types/form.twig', $withCmsUser($request, array_merge($adminContext(), [
                    'admin_nav' => 'content_types',
                    'form_mode' => 'create',
                    'content_type' => null,
                    'errors' => $result['errors'],
                    'old' => $result['values'],
                ])));
            }
            $v = $result['values'];
            $slug = ContentSlugger::ensureUniqueType($types, $v['slug']);
            $types->insert(
                $v['name'],
                $slug,
                $v['icon'],
                $v['description'],
                $v['has_public_route'],
                $v['supports_seo'],
                $v['supports_featured_image']
            );
            Flash::set('success', 'Content type created.');
            Events::dispatch(new StorefrontCachesInvalidateEvent('content_type_created'));

            return $response
                ->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.content_types.index'))
                ->withStatus(302);
        })->setName('admin.content_types.store');

        $g->get('/content-types/{id:[0-9]+}/edit', function (Request $request, Response $response, array $args) use ($twig, $adminContext, $withCmsUser, $types): Response {
            $id = (int) $args['id'];
            $t = $types->findById($id);
            if ($t === null) {
                throw new HttpNotFoundException($request);
            }

            return $twig->render($response, 'admin/content/types/form.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'content_types',
                'form_mode' => 'edit',
                'content_type' => $t,
                'errors' => [],
                'old' => null,
            ])));
        })->setName('admin.content_types.edit');

        $g->post('/content-types/{id:[0-9]+}/edit', function (Request $request, Response $response, array $args) use ($twig, $adminContext, $withCmsUser, $types, $typeValidator): Response {
            $id = (int) $args['id'];
            $t = $types->findById($id);
            if ($t === null) {
                throw new HttpNotFoundException($request);
            }
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $result = $typeValidator->validate($body, $id, $types);
            if ($result['errors'] !== []) {
                return $twig->render($response, 'admin/content/types/form.twig', $withCmsUser($request, array_merge($adminContext(), [
                    'admin_nav' => 'content_types',
                    'form_mode' => 'edit',
                    'content_type' => $t,
                    'errors' => $result['errors'],
                    'old' => $result['values'],
                ])));
            }
            $v = $result['values'];
            $slug = ContentSlugger::ensureUniqueType($types, $v['slug'], $id);
            $types->update(
                $id,
                $v['name'],
                $slug,
                $v['icon'],
                $v['description'],
                $v['has_public_route'],
                $v['supports_seo'],
                $v['supports_featured_image']
            );
            Flash::set('success', 'Content type updated.');
            Events::dispatch(new StorefrontCachesInvalidateEvent('content_type_updated'));

            return $response
                ->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.content_types.entries.index', ['id' => (string) $id]))
                ->withStatus(302);
        })->setName('admin.content_types.update');

        $g->post('/content-types/{id:[0-9]+}/delete', function (Request $request, Response $response, array $args) use ($types, $menuItems): Response {
            $id = (int) $args['id'];
            $t = $types->findById($id);
            if ($t === null) {
                throw new HttpNotFoundException($request);
            }
            $menuItems->deleteByContentTypeSlug($t->slug);
            $types->delete($id);
            Flash::set('success', 'Content type deleted.');
            Events::dispatch(new StorefrontCachesInvalidateEvent('content_type_deleted'));

            return $response
                ->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.content_types.index'))
                ->withStatus(302);
        })->setName('admin.content_types.delete');

        /* —— Fields —— */
        $g->get('/content-types/{id:[0-9]+}/fields', function (Request $request, Response $response, array $args) use ($twig, $adminContext, $withCmsUser, $types, $fields): Response {
            $id = (int) $args['id'];
            $t = $types->findById($id);
            if ($t === null) {
                throw new HttpNotFoundException($request);
            }

            return $twig->render($response, 'admin/content/fields/index.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'content_types',
                'content_type' => $t,
                'fields' => $fields->forTypeOrdered($id),
            ])));
        })->setName('admin.content_types.fields.index');

        $g->get('/content-types/{id:[0-9]+}/fields/new', function (Request $request, Response $response, array $args) use ($twig, $adminContext, $withCmsUser, $types, $fields): Response {
            $id = (int) $args['id'];
            $t = $types->findById($id);
            if ($t === null) {
                throw new HttpNotFoundException($request);
            }

            return $twig->render($response, 'admin/content/fields/form.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'content_types',
                'content_type' => $t,
                'field' => null,
                'errors' => [],
                'old' => [
                    'label' => '',
                    'field_key' => '',
                    'field_type' => 'text',
                    'placeholder' => '',
                    'help_text' => '',
                    'is_required' => false,
                    'default_value' => '',
                    'options_json' => '',
                    'sort_order' => (string) $fields->nextSortOrder($id),
                ],
            ])));
        })->setName('admin.content_types.fields.new');

        $g->post('/content-types/{id:[0-9]+}/fields/new', function (Request $request, Response $response, array $args) use ($twig, $adminContext, $withCmsUser, $types, $fields, $fieldValidator): Response {
            $id = (int) $args['id'];
            $t = $types->findById($id);
            if ($t === null) {
                throw new HttpNotFoundException($request);
            }
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $result = $fieldValidator->validate($body, $id, null, $fields);
            if ($result['errors'] !== []) {
                return $twig->render($response, 'admin/content/fields/form.twig', $withCmsUser($request, array_merge($adminContext(), [
                    'admin_nav' => 'content_types',
                    'content_type' => $t,
                    'field' => null,
                    'errors' => $result['errors'],
                    'old' => $result['values'],
                ])));
            }
            $v = $result['values'];
            $fields->insert(
                $id,
                $v['label'],
                $v['field_key'],
                $v['field_type'],
                $v['placeholder'],
                $v['help_text'],
                $v['is_required'],
                $v['default_value'],
                $v['options_json'],
                $v['sort_order']
            );
            Flash::set('success', 'Field added.');
            Events::dispatch(new StorefrontCachesInvalidateEvent('content_field_added'));

            return $response
                ->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.content_types.fields.index', ['id' => (string) $id]))
                ->withStatus(302);
        })->setName('admin.content_types.fields.store');

        $g->get('/content-types/{id:[0-9]+}/fields/{fieldId:[0-9]+}/edit', function (Request $request, Response $response, array $args) use ($twig, $adminContext, $withCmsUser, $types, $fields): Response {
            $id = (int) $args['id'];
            $fieldId = (int) $args['fieldId'];
            $t = $types->findById($id);
            if ($t === null) {
                throw new HttpNotFoundException($request);
            }
            $f = $fields->findById($fieldId);
            if ($f === null || $f->contentTypeId !== $id) {
                throw new HttpNotFoundException($request);
            }

            return $twig->render($response, 'admin/content/fields/form.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'content_types',
                'content_type' => $t,
                'field' => $f,
                'errors' => [],
                'old' => null,
            ])));
        })->setName('admin.content_types.fields.edit');

        $g->post('/content-types/{id:[0-9]+}/fields/{fieldId:[0-9]+}/edit', function (Request $request, Response $response, array $args) use ($twig, $adminContext, $withCmsUser, $types, $fields, $fieldValidator): Response {
            $id = (int) $args['id'];
            $fieldId = (int) $args['fieldId'];
            $t = $types->findById($id);
            if ($t === null) {
                throw new HttpNotFoundException($request);
            }
            $f = $fields->findById($fieldId);
            if ($f === null || $f->contentTypeId !== $id) {
                throw new HttpNotFoundException($request);
            }
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $result = $fieldValidator->validate($body, $id, $fieldId, $fields);
            if ($result['errors'] !== []) {
                return $twig->render($response, 'admin/content/fields/form.twig', $withCmsUser($request, array_merge($adminContext(), [
                    'admin_nav' => 'content_types',
                    'content_type' => $t,
                    'field' => $f,
                    'errors' => $result['errors'],
                    'old' => $result['values'],
                ])));
            }
            $v = $result['values'];
            $fields->update(
                $fieldId,
                $v['label'],
                $v['field_key'],
                $v['field_type'],
                $v['placeholder'],
                $v['help_text'],
                $v['is_required'],
                $v['default_value'],
                $v['options_json'],
                $v['sort_order']
            );
            Flash::set('success', 'Field updated.');
            Events::dispatch(new StorefrontCachesInvalidateEvent('content_field_updated'));

            return $response
                ->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.content_types.fields.index', ['id' => (string) $id]))
                ->withStatus(302);
        })->setName('admin.content_types.fields.update');

        $g->post('/content-types/{id:[0-9]+}/fields/{fieldId:[0-9]+}/delete', function (Request $request, Response $response, array $args) use ($fields): Response {
            $id = (int) $args['id'];
            $fieldId = (int) $args['fieldId'];
            if (!$fields->belongsToType($fieldId, $id)) {
                throw new HttpNotFoundException($request);
            }
            $fields->delete($fieldId);
            Flash::set('success', 'Field removed.');
            Events::dispatch(new StorefrontCachesInvalidateEvent('content_field_deleted'));

            return $response
                ->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.content_types.fields.index', ['id' => (string) $id]))
                ->withStatus(302);
        })->setName('admin.content_types.fields.delete');

        $g->post('/content-types/{id:[0-9]+}/fields/reorder', function (Request $request, Response $response, array $args) use ($types, $fields): Response {
            $id = (int) $args['id'];
            if ($types->findById($id) === null) {
                throw new HttpNotFoundException($request);
            }
            $body = $request->getParsedBody();
            $order = is_array($body) && isset($body['order']) && is_array($body['order']) ? $body['order'] : [];
            foreach ($order as $fidRaw => $sortRaw) {
                $fid = (int) $fidRaw;
                if ($fid < 1 || !$fields->belongsToType($fid, $id)) {
                    continue;
                }
                $fields->updateSortOrder($fid, $id, (int) $sortRaw);
            }
            Flash::set('success', 'Field order saved.');
            Events::dispatch(new StorefrontCachesInvalidateEvent('content_fields_reordered'));

            return $response
                ->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.content_types.fields.index', ['id' => (string) $id]))
                ->withStatus(302);
        })->setName('admin.content_types.fields.reorder');
        })->add($permTypes);

        $group->get('/content-types/{id:[0-9]+}', function (Request $request, Response $response, array $args): Response {
            $id = (int) $args['id'];
            $target = RouteContext::fromRequest($request)->getRouteParser()->urlFor(
                'admin.content_types.entries.index',
                ['id' => (string) $id]
            );

            return $response->withHeader('Location', $target)->withStatus(302);
        })->setName('admin.content_types.show')->add($permEntryBrowse);

        /* —— Entries (merged former type hub + full list) —— */
        $group->get('/content-types/{id:[0-9]+}/entries', function (Request $request, Response $response, array $args) use ($twig, $adminContext, $withCmsUser, $types, $fields, $entries, $taxonomyRepo): Response {
            $id = (int) $args['id'];
            $t = $types->findById($id);
            if ($t === null) {
                throw new HttpNotFoundException($request);
            }
            $fieldList = $fields->forTypeOrdered($id);
            $taxList = $taxonomyRepo->forContentTypeOrdered($id);
            $entryRows = $entries->forTypeOrdered($id, 500);
            $entrySummary = ['total' => count($entryRows), 'published' => 0, 'draft' => 0, 'in_review' => 0];
            foreach ($entryRows as $row) {
                $st = (string) ($row['status'] ?? '');
                if ($st === 'published') {
                    ++$entrySummary['published'];
                } elseif ($st === 'in_review') {
                    ++$entrySummary['in_review'];
                } elseif ($st === 'draft') {
                    ++$entrySummary['draft'];
                }
            }

            return $twig->render($response, 'admin/content/entries/index.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'content_types',
                'content_type' => $t,
                'field_count' => count($fieldList),
                'taxonomy_count' => count($taxList),
                'entry_rows' => $entryRows,
                'entry_summary' => $entrySummary,
            ])));
        })->setName('admin.content_types.entries.index')->add($permEntryBrowse);

        $group->get('/content-types/{id:[0-9]+}/entries/new', function (Request $request, Response $response, array $args) use ($twig, $adminContext, $withCmsUser, $types, $fields, $mediaRepo, $taxonomyRepo, $taxonomyTermRepo, $pdo, $entryFormMediaContext, $entryPrimaryRichtextTextareaId): Response {
            $id = (int) $args['id'];
            $t = $types->findById($id);
            if ($t === null) {
                throw new HttpNotFoundException($request);
            }
            $fieldList = $fields->forTypeOrdered($id);
            $taxonomies = $taxonomyRepo->forContentTypeOrdered($id);
            $taxonomy_term_rows = [];
            foreach ($taxonomies as $tx) {
                $taxonomy_term_rows[$tx->id] = TaxonomyTermTree::rowsWithDepth(
                    $taxonomyTermRepo->forTaxonomyOrdered($tx->id),
                    $tx->isHierarchical
                );
            }

            return $twig->render($response, 'admin/content/entries/form.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'content_types',
                'content_type' => $t,
                'fields' => $fieldList,
                'taxonomies' => $taxonomies,
                'taxonomy_term_rows' => $taxonomy_term_rows,
                'selected_by_taxonomy' => [],
                'entry' => null,
                'value_map' => [],
                'errors' => [],
                'old' => null,
                'media_picker_images' => $mediaRepo->listImagesForPicker(200),
                'seo_media_select' => $mediaRepo->listImagesForPicker(200),
                'workflow_statuses' => WorkflowService::STATUSES,
                'entry_primary_richtext_textarea_id' => $entryPrimaryRichtextTextareaId($fieldList),
                'entry_link_warnings' => [],
            ], $entryFormMediaContext($request, $mediaRepo, $pdo, null, null))));
        })->setName('admin.content_types.entries.new')->add($permEntryCreate);

        $group->post('/content-types/{id:[0-9]+}/entries/new', function (Request $request, Response $response, array $args) use (
            $twig,
            $adminContext,
            $withCmsUser,
            $cmsUserId,
            $types,
            $fields,
            $entries,
            $values,
            $mediaRepo,
            $entryValidator,
            $taxonomyRepo,
            $taxonomyTermRepo,
            $entryTaxonomyRepo,
            $entryTaxonomyValidator,
            $workflow,
            $entryRevRepo,
            $activity,
            $mergeEntrySeoOld,
            $pdo,
            $entryFormMediaContext,
            $entryPrimaryRichtextTextareaId,
            $viewData
        ): Response {
            $id = (int) $args['id'];
            $t = $types->findById($id);
            if ($t === null) {
                throw new HttpNotFoundException($request);
            }
            $fieldList = $fields->forTypeOrdered($id);
            $taxonomies = $taxonomyRepo->forContentTypeOrdered($id);
            $taxonomy_term_rows = [];
            foreach ($taxonomies as $tx) {
                $taxonomy_term_rows[$tx->id] = TaxonomyTermTree::rowsWithDepth(
                    $taxonomyTermRepo->forTaxonomyOrdered($tx->id),
                    $tx->isHierarchical
                );
            }
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $result = $entryValidator->validate($body, $t, $fieldList, $entries, $types, $mediaRepo, null);
            $taxResult = $entryTaxonomyValidator->validate($body, $taxonomies, $taxonomyTermRepo);
            $seoParsed = [
                'errors' => [],
                'canonical_url' => null,
                'seo_noindex' => false,
                'og_title' => null,
                'og_description' => null,
                'og_image_id' => null,
                'twitter_title' => null,
                'twitter_description' => null,
                'twitter_image_id' => null,
                'schema_json' => null,
            ];
            if ($t->supportsSeo) {
                $seoParsed = SeoFormParser::parse($body, $mediaRepo);
            }
            $allErrors = array_merge($result['errors'], $taxResult['errors'], $seoParsed['errors']);
            /** @var array<string, mixed> $cmsUser */
            $cmsUser = $request->getAttribute('cms_user') ?? [];
            $perms = $cmsUser['permission_slugs'] ?? [];
            if ($allErrors === [] && !$workflow->canTransition($perms, 'draft', $result['values']['status'])) {
                $allErrors['status'] = 'You cannot set this status.';
            }
            if ($allErrors !== []) {
                $old = array_merge($mergeEntrySeoOld($result['values'], $body), [
                    'custom_fields' => is_array($body['custom_fields'] ?? null) ? $body['custom_fields'] : [],
                ]);

                return $twig->render($response, 'admin/content/entries/form.twig', $withCmsUser($request, array_merge($adminContext(), [
                    'admin_nav' => 'content_types',
                    'content_type' => $t,
                    'fields' => $fieldList,
                    'taxonomies' => $taxonomies,
                    'taxonomy_term_rows' => $taxonomy_term_rows,
                    'selected_by_taxonomy' => !empty($body['taxonomy_terms_submitted'])
                        ? EntryTaxonomyValidator::selectionsFromBody($body)
                        : [],
                    'entry' => null,
                    'value_map' => [],
                    'errors' => $allErrors,
                    'old' => $old,
                    'media_picker_images' => $mediaRepo->listImagesForPicker(200),
                    'seo_media_select' => $mediaRepo->listImagesForPicker(200),
                    'workflow_statuses' => WorkflowService::STATUSES,
                    'entry_primary_richtext_textarea_id' => $entryPrimaryRichtextTextareaId($fieldList),
                    'entry_link_warnings' => ContentEntryRefsGuard::warnings(
                        $fieldList,
                        $result['values']['custom'],
                        null,
                        $entries,
                        $types
                    ),
                ], $entryFormMediaContext($request, $mediaRepo, $pdo, null, $old))));
            }
            $v = $result['values'];
            $slug = ContentSlugger::ensureUniqueEntry($entries, $id, $v['slug']);
            $eid = $entries->insert(
                $id,
                $v['title'],
                $slug,
                $v['status'],
                $v['featured_image_id'],
                $v['seo_title'],
                $v['seo_description'],
                $seoParsed['canonical_url'],
                $seoParsed['seo_noindex'],
                $seoParsed['og_title'],
                $seoParsed['og_description'],
                $seoParsed['og_image_id'],
                $seoParsed['twitter_title'],
                $seoParsed['twitter_description'],
                $seoParsed['twitter_image_id'],
                $seoParsed['schema_json'],
                $v['published_at'],
                $v['scheduled_publish_at'] ?? null,
                $v['scheduled_unpublish_at'] ?? null,
                $cmsUserId($request)
            );
            foreach ($fieldList as $f) {
                $val = $v['custom'][$f->id] ?? null;
                $values->upsert($eid, $f->id, $val);
            }
            EntryTaxonomySync::sync($eid, $taxResult['term_ids'], $entryTaxonomyRepo);
            $row = $entries->fetchRowById($eid);
            if ($row !== null) {
                $entryRevRepo->capture($eid, $row, $values->valuesByFieldIdForEntry($eid), $cmsUserId($request));
            }
            $activity->log($cmsUserId($request), 'content_entry.created', 'content_entry', $eid, ['content_type_id' => $id]);
            Events::dispatch(new ContentEntrySavedEvent($eid, $id, true));
            $parser = RouteContext::fromRequest($request)->getRouteParser();
            $siteUrl = (string) (($viewData())['site_url'] ?? '');
            if (AfterSaveRedirect::wantsPublicView($body)) {
                $viewUrl = AfterSaveRedirect::entryPublicUrl($siteUrl, $t, $slug, $v['status'], $v['published_at'] ?? null);
                if ($viewUrl !== null) {
                    Flash::set('success', 'Entry created.');

                    return $response->withHeader('Location', $viewUrl)->withStatus(302);
                }
                Flash::set('success', 'Entry created. Publish it and ensure the type has a public route to open it on the site.');
            } else {
                Flash::set('success', 'Entry created.');
            }

            return $response
                ->withHeader('Location', $parser->urlFor('admin.content_types.entries.edit', ['id' => (string) $id, 'entryId' => (string) $eid]))
                ->withStatus(302);
        })->setName('admin.content_types.entries.store')->add($permEntryCreate);

        $group->get('/content-types/{id:[0-9]+}/entries/{entryId:[0-9]+}/edit', function (Request $request, Response $response, array $args) use ($twig, $adminContext, $withCmsUser, $types, $fields, $entries, $values, $mediaRepo, $taxonomyRepo, $taxonomyTermRepo, $entryTaxonomyRepo, $workflow, $entryRevRepo, $pdo, $entryFormMediaContext, $entryPrimaryRichtextTextareaId): Response {
            $id = (int) $args['id'];
            $entryId = (int) $args['entryId'];
            $t = $types->findById($id);
            if ($t === null) {
                throw new HttpNotFoundException($request);
            }
            $entry = $entries->findById($entryId);
            if ($entry === null || $entry->contentTypeId !== $id) {
                throw new HttpNotFoundException($request);
            }
            $fieldList = $fields->forTypeOrdered($id);
            $valueMap = $values->valuesByFieldIdForEntry($entryId);
            $entryLinkWarnings = ContentEntryRefsGuard::warnings($fieldList, $valueMap, $entryId, $entries, $types);
            $taxonomies = $taxonomyRepo->forContentTypeOrdered($id);
            $taxonomy_term_rows = [];
            foreach ($taxonomies as $tx) {
                $taxonomy_term_rows[$tx->id] = TaxonomyTermTree::rowsWithDepth(
                    $taxonomyTermRepo->forTaxonomyOrdered($tx->id),
                    $tx->isHierarchical
                );
            }

            /** @var array<string, mixed> $cmsUser */
            $cmsUser = $request->getAttribute('cms_user') ?? [];
            $perms = $cmsUser['permission_slugs'] ?? [];

            return $twig->render($response, 'admin/content/entries/form.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'content_types',
                'content_type' => $t,
                'fields' => $fieldList,
                'taxonomies' => $taxonomies,
                'taxonomy_term_rows' => $taxonomy_term_rows,
                'selected_by_taxonomy' => $entryTaxonomyRepo->termIdsByTaxonomyForEntry($entryId),
                'entry' => $entry,
                'value_map' => $valueMap,
                'errors' => [],
                'old' => null,
                'media_picker_images' => $mediaRepo->listImagesForPicker(200),
                'seo_media_select' => $mediaRepo->listImagesForPicker(200),
                'workflow_statuses' => $workflow->allowedTargets($perms, $entry->status),
                'entry_revision_rows' => $entryRevRepo->listForEntry($entryId, 15),
                'entry_primary_richtext_textarea_id' => $entryPrimaryRichtextTextareaId($fieldList),
                'entry_link_warnings' => $entryLinkWarnings,
            ], $entryFormMediaContext($request, $mediaRepo, $pdo, $entry, null))));
        })->setName('admin.content_types.entries.edit')->add($permEntryEdit);

        $group->post('/content-types/{id:[0-9]+}/entries/{entryId:[0-9]+}/edit', function (Request $request, Response $response, array $args) use (
            $twig,
            $adminContext,
            $withCmsUser,
            $cmsUserId,
            $types,
            $fields,
            $entries,
            $values,
            $mediaRepo,
            $entryValidator,
            $taxonomyRepo,
            $taxonomyTermRepo,
            $entryTaxonomyRepo,
            $entryTaxonomyValidator,
            $workflow,
            $entryRevRepo,
            $activity,
            $pdo,
            $viewData,
            $mergeEntrySeoOld,
            $entryFormMediaContext,
            $entryPrimaryRichtextTextareaId
        ): Response {
            $id = (int) $args['id'];
            $entryId = (int) $args['entryId'];
            $t = $types->findById($id);
            if ($t === null) {
                throw new HttpNotFoundException($request);
            }
            $entry = $entries->findById($entryId);
            if ($entry === null || $entry->contentTypeId !== $id) {
                throw new HttpNotFoundException($request);
            }
            $fieldList = $fields->forTypeOrdered($id);
            $taxonomies = $taxonomyRepo->forContentTypeOrdered($id);
            $taxonomy_term_rows = [];
            foreach ($taxonomies as $tx) {
                $taxonomy_term_rows[$tx->id] = TaxonomyTermTree::rowsWithDepth(
                    $taxonomyTermRepo->forTaxonomyOrdered($tx->id),
                    $tx->isHierarchical
                );
            }
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $result = $entryValidator->validate($body, $t, $fieldList, $entries, $types, $mediaRepo, $entryId);
            $taxResult = $entryTaxonomyValidator->validate($body, $taxonomies, $taxonomyTermRepo);
            $seoParsed = [
                'errors' => [],
                'canonical_url' => null,
                'seo_noindex' => false,
                'og_title' => null,
                'og_description' => null,
                'og_image_id' => null,
                'twitter_title' => null,
                'twitter_description' => null,
                'twitter_image_id' => null,
                'schema_json' => null,
            ];
            if ($t->supportsSeo) {
                $seoParsed = SeoFormParser::parse($body, $mediaRepo);
            }
            $allErrors = array_merge($result['errors'], $taxResult['errors'], $seoParsed['errors']);
            /** @var array<string, mixed> $cmsUser */
            $cmsUser = $request->getAttribute('cms_user') ?? [];
            $perms = $cmsUser['permission_slugs'] ?? [];
            if ($allErrors === [] && !$workflow->canTransition($perms, $entry->status, $result['values']['status'])) {
                $allErrors['status'] = 'You cannot change status to this value for your role.';
            }
            if ($allErrors !== []) {
                $old = array_merge($mergeEntrySeoOld($result['values'], $body), [
                    'custom_fields' => is_array($body['custom_fields'] ?? null) ? $body['custom_fields'] : [],
                ]);

                return $twig->render($response, 'admin/content/entries/form.twig', $withCmsUser($request, array_merge($adminContext(), [
                    'admin_nav' => 'content_types',
                    'content_type' => $t,
                    'fields' => $fieldList,
                    'taxonomies' => $taxonomies,
                    'taxonomy_term_rows' => $taxonomy_term_rows,
                    'selected_by_taxonomy' => !empty($body['taxonomy_terms_submitted'])
                        ? EntryTaxonomyValidator::selectionsFromBody($body)
                        : $entryTaxonomyRepo->termIdsByTaxonomyForEntry($entryId),
                    'entry' => $entry,
                    'value_map' => $values->valuesByFieldIdForEntry($entryId),
                    'errors' => $allErrors,
                    'old' => $old,
                    'media_picker_images' => $mediaRepo->listImagesForPicker(200),
                    'seo_media_select' => $mediaRepo->listImagesForPicker(200),
                    'workflow_statuses' => $workflow->allowedTargets($perms, $entry->status),
                    'entry_revision_rows' => $entryRevRepo->listForEntry($entryId, 15),
                    'entry_primary_richtext_textarea_id' => $entryPrimaryRichtextTextareaId($fieldList),
                    'entry_link_warnings' => ContentEntryRefsGuard::warnings(
                        $fieldList,
                        $result['values']['custom'],
                        $entryId,
                        $entries,
                        $types
                    ),
                ], $entryFormMediaContext($request, $mediaRepo, $pdo, $entry, $old))));
            }
            $v = $result['values'];
            $slug = ContentSlugger::ensureUniqueEntry($entries, $id, $v['slug'], $entryId);
            $prevRow = $entries->fetchRowById($entryId);
            if ($prevRow !== null) {
                $entryRevRepo->capture($entryId, $prevRow, $values->valuesByFieldIdForEntry($entryId), $cmsUserId($request));
            }
            $oldSlug = $entry->slug;
            $entries->update(
                $entryId,
                $v['title'],
                $slug,
                $v['status'],
                $v['featured_image_id'],
                $v['seo_title'],
                $v['seo_description'],
                $seoParsed['canonical_url'],
                $seoParsed['seo_noindex'],
                $seoParsed['og_title'],
                $seoParsed['og_description'],
                $seoParsed['og_image_id'],
                $seoParsed['twitter_title'],
                $seoParsed['twitter_description'],
                $seoParsed['twitter_image_id'],
                $seoParsed['schema_json'],
                $v['published_at'],
                $v['scheduled_publish_at'] ?? null,
                $v['scheduled_unpublish_at'] ?? null
            );
            if ($entry->status === 'published' && $t->hasPublicRoute && $oldSlug !== $slug) {
                $base = rtrim((string) ($viewData()['site_url'] ?? ''), '/');
                (new RedirectRepository($pdo))->upsertPath(
                    '/' . $t->slug . '/' . $oldSlug,
                    $base . '/' . $t->slug . '/' . $slug,
                    301
                );
            }
            foreach ($fieldList as $f) {
                $values->upsert($entryId, $f->id, $v['custom'][$f->id] ?? null);
            }
            EntryTaxonomySync::sync($entryId, $taxResult['term_ids'], $entryTaxonomyRepo);
            $activity->log($cmsUserId($request), 'content_entry.updated', 'content_entry', $entryId, ['content_type_id' => $id]);
            Events::dispatch(new ContentEntrySavedEvent($entryId, $id, false));
            $parser = RouteContext::fromRequest($request)->getRouteParser();
            $siteUrl = (string) (($viewData())['site_url'] ?? '');
            if (AfterSaveRedirect::wantsPublicView($body)) {
                $viewUrl = AfterSaveRedirect::entryPublicUrl($siteUrl, $t, $slug, $v['status'], $v['published_at'] ?? null);
                if ($viewUrl !== null) {
                    Flash::set('success', 'Entry updated.');

                    return $response->withHeader('Location', $viewUrl)->withStatus(302);
                }
                Flash::set('success', 'Entry saved. Publish it and ensure the type has a public route to view it on the site.');
            } else {
                Flash::set('success', 'Entry updated.');
            }

            return $response
                ->withHeader('Location', $parser->urlFor('admin.content_types.entries.edit', ['id' => (string) $id, 'entryId' => (string) $entryId]))
                ->withStatus(302);
        })->setName('admin.content_types.entries.update')->add($permEntryEdit);

        $group->get('/content-types/{id:[0-9]+}/entries/{entryId:[0-9]+}/revisions', function (Request $request, Response $response, array $args) use ($twig, $adminContext, $withCmsUser, $types, $entries, $entryRevRepo): Response {
            $id = (int) $args['id'];
            $entryId = (int) $args['entryId'];
            $t = $types->findById($id);
            $entry = $entries->findById($entryId);
            if ($t === null || $entry === null || $entry->contentTypeId !== $id) {
                throw new HttpNotFoundException($request);
            }

            return $twig->render($response, 'admin/content/entries/revisions.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'content_types',
                'content_type' => $t,
                'entry' => $entry,
                'revision_rows' => $entryRevRepo->listForEntry($entryId),
            ])));
        })->setName('admin.content_types.entries.revisions')->add($permEntryEdit);

        $group->get('/content-types/{id:[0-9]+}/entries/{entryId:[0-9]+}/revisions/compare/{revId:[0-9]+}', function (Request $request, Response $response, array $args) use ($twig, $adminContext, $withCmsUser, $types, $entries, $entryRevRepo, $values): Response {
            $id = (int) $args['id'];
            $entryId = (int) $args['entryId'];
            $revId = (int) $args['revId'];
            $t = $types->findById($id);
            $entry = $entries->findById($entryId);
            $rev = $entryRevRepo->findById($revId);
            if ($t === null || $entry === null || $entry->contentTypeId !== $id || $rev === null || (int) $rev['content_entry_id'] !== $entryId) {
                throw new HttpNotFoundException($request);
            }
            $snap = json_decode((string) $rev['snapshot_json'], true);
            $snap = is_array($snap) ? $snap : [];
            $valuesSnap = isset($snap['values']) && is_array($snap['values']) ? $snap['values'] : [];

            $q = $request->getQueryParams();
            $otherRaw = isset($q['other']) ? (string) $q['other'] : '';
            $otherId = ctype_digit($otherRaw) ? (int) $otherRaw : 0;
            $revRight = null;
            $snapshotRight = [];
            $revUnifiedDiff = '';

            if ($otherId > 0 && $otherId !== $revId) {
                $otherRow = $entryRevRepo->findById($otherId);
                if ($otherRow !== null && (int) $otherRow['content_entry_id'] === $entryId) {
                    $revRight = $otherRow;
                    $snapshotRight = json_decode((string) $otherRow['snapshot_json'], true);
                    $snapshotRight = is_array($snapshotRight) ? $snapshotRight : [];
                    $vsOther = isset($snapshotRight['values']) && is_array($snapshotRight['values']) ? $snapshotRight['values'] : [];
                    $leftStr = json_encode($valuesSnap, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                    $rightStr = json_encode($vsOther, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                    $revUnifiedDiff = implode("\n", \App\Support\LineDiff::unified($leftStr, $rightStr));
                }
            }
            if ($revUnifiedDiff === '') {
                $snapJson = json_encode($valuesSnap, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                $currentJson = json_encode($values->valuesByFieldIdForEntry($entryId), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                $revUnifiedDiff = implode("\n", \App\Support\LineDiff::unified($snapJson, $currentJson));
            }

            return $twig->render($response, 'admin/content/entries/revision_compare.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'content_types',
                'content_type' => $t,
                'entry' => $entry,
                'revision' => $rev,
                'snapshot' => $snap,
                'revision_right' => $revRight,
                'snapshot_right' => $snapshotRight,
                'current_value_map' => $values->valuesByFieldIdForEntry($entryId),
                'revision_unified_diff' => $revUnifiedDiff,
            ])));
        })->setName('admin.content_types.entries.revision_compare')->add($permEntryEdit);

        $group->post('/content-types/{id:[0-9]+}/entries/{entryId:[0-9]+}/preview-link', function (Request $request, Response $response, array $args) use ($types, $entries, $pdo, $viewData, $cmsUserId): Response {
            $id = (int) $args['id'];
            $entryId = (int) $args['entryId'];
            $t = $types->findById($id);
            $entry = $entries->findById($entryId);
            if ($t === null || $entry === null || $entry->contentTypeId !== $id) {
                throw new HttpNotFoundException($request);
            }
            if (!$t->hasPublicRoute) {
                Flash::set('error', 'Preview links require a public route on this content type.');

                return $response
                    ->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.content_types.entries.edit', ['id' => (string) $id, 'entryId' => (string) $entryId]))
                    ->withStatus(302);
            }
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $ttlChoices = [3600 => true, 86400 => true, 604800 => true];
            $ttlRaw = (string) ($body['preview_link_ttl'] ?? '86400');
            $ttl = ctype_digit($ttlRaw) ? (int) $ttlRaw : 86400;
            if (!isset($ttlChoices[$ttl])) {
                $ttl = 86400;
            }
            $plain = (new PreviewTokenRepository($pdo))->mint('content_entry', $entryId, $ttl, $cmsUserId($request));
            $parser = RouteContext::fromRequest($request)->getRouteParser();
            $site = rtrim((string) (($viewData())['site_url'] ?? ''), '/');
            $path = $parser->urlFor('public.preview.content_entry', ['entryId' => (string) $entryId], ['token' => $plain]);
            $url = $site !== '' ? ($site . $path) : $path;
            Flash::set('success', 'Stakeholder preview link (copy now; expires automatically): ' . $url);

            return $response
                ->withHeader('Location', $parser->urlFor('admin.content_types.entries.edit', ['id' => (string) $id, 'entryId' => (string) $entryId]))
                ->withStatus(302);
        })->setName('admin.content_types.entries.preview_link')->add($permEntryEdit);

        $group->post('/content-types/{id:[0-9]+}/entries/{entryId:[0-9]+}/revisions/{revId:[0-9]+}/restore', function (Request $request, Response $response, array $args) use ($types, $entries, $values, $entryRevRepo, $workflow, $activity, $cmsUserId): Response {
            $id = (int) $args['id'];
            $entryId = (int) $args['entryId'];
            $revId = (int) $args['revId'];
            $t = $types->findById($id);
            $entry = $entries->findById($entryId);
            $rev = $entryRevRepo->findById($revId);
            if ($t === null || $entry === null || $entry->contentTypeId !== $id || $rev === null || (int) $rev['content_entry_id'] !== $entryId) {
                throw new HttpNotFoundException($request);
            }
            /** @var array<string, mixed> $cmsUser */
            $cmsUser = $request->getAttribute('cms_user') ?? [];
            $perms = $cmsUser['permission_slugs'] ?? [];
            $snap = json_decode((string) $rev['snapshot_json'], true);
            if (!is_array($snap) || !isset($snap['entry']) || !is_array($snap['entry'])) {
                Flash::set('error', 'Invalid revision data.');

                return $response
                    ->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.content_types.entries.revisions', ['id' => (string) $id, 'entryId' => (string) $entryId]))
                    ->withStatus(302);
            }
            $er = $snap['entry'];
            $targetStatus = (string) ($er['status'] ?? 'draft');
            if (!$workflow->canTransition($perms, $entry->status, $targetStatus)) {
                Flash::set('error', 'You cannot restore to this status.');

                return $response
                    ->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.content_types.entries.revisions', ['id' => (string) $id, 'entryId' => (string) $entryId]))
                    ->withStatus(302);
            }
            $prevRow = $entries->fetchRowById($entryId);
            if ($prevRow !== null) {
                $entryRevRepo->capture($entryId, $prevRow, $values->valuesByFieldIdForEntry($entryId), $cmsUserId($request));
            }
            $pub = isset($er['published_at']) && $er['published_at'] !== '' && $er['published_at'] !== null
                ? (string) $er['published_at']
                : null;
            $fid = $er['featured_image_id'] ?? null;
            $featured = $fid !== null && $fid !== '' ? (int) $fid : null;
            $seoT = isset($er['seo_title']) && is_string($er['seo_title']) ? $er['seo_title'] : null;
            $seoD = isset($er['seo_description']) && is_string($er['seo_description']) ? $er['seo_description'] : null;
            $canon = isset($er['canonical_url']) && (string) $er['canonical_url'] !== '' ? (string) $er['canonical_url'] : null;
            $noindex = !empty($er['seo_noindex']);
            $ogT = isset($er['og_title']) && (string) $er['og_title'] !== '' ? (string) $er['og_title'] : null;
            $ogD = isset($er['og_description']) && (string) $er['og_description'] !== '' ? (string) $er['og_description'] : null;
            $ogImg = isset($er['og_image_id']) && $er['og_image_id'] !== null && $er['og_image_id'] !== '' ? (int) $er['og_image_id'] : null;
            $twT = isset($er['twitter_title']) && (string) $er['twitter_title'] !== '' ? (string) $er['twitter_title'] : null;
            $twD = isset($er['twitter_description']) && (string) $er['twitter_description'] !== '' ? (string) $er['twitter_description'] : null;
            $twImg = isset($er['twitter_image_id']) && $er['twitter_image_id'] !== null && $er['twitter_image_id'] !== '' ? (int) $er['twitter_image_id'] : null;
            $schema = isset($er['schema_json']) && (string) $er['schema_json'] !== '' ? (string) $er['schema_json'] : null;
            $entries->update(
                $entryId,
                (string) $er['title'],
                (string) $er['slug'],
                $targetStatus,
                $featured,
                $seoT,
                $seoD,
                $canon,
                $noindex,
                $ogT,
                $ogD,
                $ogImg,
                $twT,
                $twD,
                $twImg,
                $schema,
                $pub,
                isset($er['scheduled_publish_at']) && $er['scheduled_publish_at'] !== null && (string) $er['scheduled_publish_at'] !== ''
                    ? (string) $er['scheduled_publish_at'] : null,
                isset($er['scheduled_unpublish_at']) && $er['scheduled_unpublish_at'] !== null && (string) $er['scheduled_unpublish_at'] !== ''
                    ? (string) $er['scheduled_unpublish_at'] : null
            );
            $vals = isset($snap['values']) && is_array($snap['values']) ? $snap['values'] : [];
            $values->deleteForEntry($entryId);
            foreach ($vals as $fieldId => $val) {
                $str = $val === null ? null : (is_string($val) ? $val : (string) $val);
                $values->upsert($entryId, (int) $fieldId, $str);
            }
            $activity->log($cmsUserId($request), 'content_entry.revision_restored', 'content_entry', $entryId, ['revision_id' => $revId]);
            Flash::set('success', 'Revision restored.');

            return $response
                ->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.content_types.entries.edit', ['id' => (string) $id, 'entryId' => (string) $entryId]))
                ->withStatus(302);
        })->setName('admin.content_types.entries.revision_restore')->add($permEntryEdit);

        $group->post('/content-types/{id:[0-9]+}/entries/{entryId:[0-9]+}/delete', function (Request $request, Response $response, array $args) use ($entries, $activity, $cmsUserId): Response {
            $id = (int) $args['id'];
            $entryId = (int) $args['entryId'];
            if (!$entries->belongsToType($entryId, $id)) {
                throw new HttpNotFoundException($request);
            }
            Events::dispatch(new ContentEntryDeletedEvent($entryId, $id));
            $activity->log($cmsUserId($request), 'content_entry.deleted', 'content_entry', $entryId, ['content_type_id' => $id]);
            $entries->delete($entryId);
            Flash::set('success', 'Entry deleted.');

            return $response
                ->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.content_types.entries.index', ['id' => (string) $id]))
                ->withStatus(302);
        })->setName('admin.content_types.entries.delete')->add($permDelete);
    })->add($middleware);
};
