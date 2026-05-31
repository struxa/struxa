<?php

declare(strict_types=1);

use App\Admin\AfterSaveRedirect;
use App\Access\ActivityLogger;
use App\Access\PermissionSlug;
use App\Access\WorkflowService;
use App\Editing\ContentAutosaveRepository;
use App\Editing\EditLockRepository;
use App\Editing\EditLockService;
use App\Editing\EditSessionContext;
use App\Editing\EditSubjectType;
use App\Event\StorefrontCachesInvalidateEvent;
use App\Flash;
use App\Http\Middleware\RequireCmsStaff;
use App\Http\Middleware\RequirePermission;
use App\Media\MediaRepository;
use App\Media\MediaUrlHelper;
use App\Media\MediaUploadService;
use App\Page\Page;
use App\Page\PageContentSanitizer;
use App\Page\PagePreviewFactory;
use App\Page\PageRepository;
use App\Page\PageRevisionRepository;
use App\Page\PageSlugger;
use App\Page\PageTagParser;
use App\Page\PageValidator;
use App\Preview\PreviewTokenRepository;
use App\Support\LineDiff;
use App\Section\PageSectionRepository;
use App\Section\PageSection;
use App\Section\SectionManager;
use App\Section\SectionRenderer;
use App\Section\SectionSchemaValidator;
use App\Section\SectionTemplateResolver;
use App\Seo\ExternalLinkPolicy;
use App\Seo\MetaTagBuilder;
use App\Seo\RedirectRepository;
use App\Seo\SeoFormParser;
use App\Seo\SeoService;
use App\Settings;
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
    $permPages = new RequirePermission($pdo, [PermissionSlug::MANAGE_PAGES]);
    $permDelete = new RequirePermission($pdo, [PermissionSlug::DELETE_CONTENT]);
    $repo = new PageRepository($pdo);
    $revisions = new PageRevisionRepository($pdo);
    $validator = new PageValidator();
    $workflow = new WorkflowService();
    $activity = new ActivityLogger($pdo);
    $pageSections = new PageSectionRepository($pdo);
    $sectionManager = new SectionManager();
    $sectionRenderer = new SectionRenderer($sectionManager, new SectionTemplateResolver($sectionManager));
    $sectionValidator = new SectionSchemaValidator($sectionManager);
    $mediaRepo = new MediaRepository($pdo);
    $editSessions = new EditSessionContext(new EditLockService(new EditLockRepository($pdo)), new ContentAutosaveRepository($pdo));

    $adminContext = static function () use ($viewData): array {
        return array_merge($viewData(), []);
    };

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

    $appendFeaturedMediaError = static function (array $result) use ($mediaRepo): array {
        if ($result['errors'] !== []) {
            return $result;
        }
        $fid = $result['values']['featured_image_id'] ?? null;
        if ($fid !== null && !$mediaRepo->isImageId((int) $fid)) {
            $result['errors']['featured_image_id'] = 'Choose a valid image from the media library.';
        }

        return $result;
    };

    $pageFormFeaturedThumb = static function (?Page $page, ?array $old) use ($pdo): array {
        if ($old !== null && array_key_exists('featured_image_id', $old) && $old['featured_image_id'] !== null) {
            return ['page_edit_featured_url' => (new MediaUrlHelper($pdo))->pathForId((int) $old['featured_image_id'])];
        }
        if ($page !== null && $page->featuredImageId !== null) {
            return ['page_edit_featured_url' => (new MediaUrlHelper($pdo))->pathForId($page->featuredImageId)];
        }

        return ['page_edit_featured_url' => ''];
    };

    $mergePageSeoOld = static function (array $values, array $body): array {
        return array_merge($values, [
            'canonical_url' => trim((string) ($body['canonical_url'] ?? '')),
            'seo_noindex' => !empty($body['seo_noindex']),
            'og_title' => trim((string) ($body['og_title'] ?? '')),
            'og_description' => trim((string) ($body['og_description'] ?? '')),
            'og_image_id' => trim((string) ($body['og_image_id'] ?? '')),
            'twitter_title' => trim((string) ($body['twitter_title'] ?? '')),
            'twitter_description' => trim((string) ($body['twitter_description'] ?? '')),
            'twitter_image_id' => trim((string) ($body['twitter_image_id'] ?? '')),
            'schema_json' => (string) ($body['schema_json'] ?? ''),
            'focus_keyphrase' => trim((string) ($body['focus_keyphrase'] ?? '')),
            'published_at' => trim((string) ($body['published_at'] ?? '')),
            'scheduled_publish_at' => trim((string) ($body['scheduled_publish_at'] ?? '')),
            'scheduled_unpublish_at' => trim((string) ($body['scheduled_unpublish_at'] ?? '')),
        ]);
    };

    $app->group('/admin', function (\Slim\Routing\RouteCollectorProxy $group) use (
        $twig,
        $viewData,
        $pdo,
        $adminContext,
        $withCmsUser,
        $cmsUid,
        $repo,
        $revisions,
        $validator,
        $workflow,
        $activity,
        $permDelete,
        $pageSections,
        $sectionManager,
        $sectionRenderer,
        $sectionValidator,
        $mediaRepo,
        $appendFeaturedMediaError,
        $pageFormFeaturedThumb,
        $mergePageSeoOld
    ): void {
        $pageFormMediaPicker = static function (Request $request) use ($mediaRepo): array {
            /** @var array<string, mixed> $cmsUser */
            $cmsUser = $request->getAttribute('cms_user') ?? [];
            $slugs = $cmsUser['permission_slugs'] ?? [];
            if (!is_array($slugs) || !in_array(PermissionSlug::MANAGE_MEDIA, $slugs, true)) {
                return [
                    'media_picker_enabled' => false,
                    'media_picker_initial' => [],
                    'media_picker_max_mb' => (int) round(MediaUploadService::maxBytesFromEnv() / 1024 / 1024),
                ];
            }

            $rows = $mediaRepo->listImagesForPicker(240);
            $picker = [];
            foreach ($rows as $r) {
                if (($r['public_url'] ?? '') === '') {
                    continue;
                }
                $picker[] = [
                    'id' => $r['id'],
                    'url' => $r['public_url'],
                    'name' => $r['original_name'],
                ];
            }

            return [
                'media_picker_enabled' => true,
                'media_picker_initial' => $picker,
                'media_picker_max_mb' => (int) round(MediaUploadService::maxBytesFromEnv() / 1024 / 1024),
            ];
        };

        $pageFormRevisionSidebar = static function (?int $pageId) use ($revisions): array {
            if ($pageId === null || $pageId <= 0) {
                return [
                    'page_edit_revisions_preview' => [],
                    'page_edit_revisions_has_more' => false,
                ];
            }
            $preview = $revisions->listPreviewForSidebar($pageId, 3, 1);

            return [
                'page_edit_revisions_preview' => $preview['rows'],
                'page_edit_revisions_has_more' => $preview['has_more'],
            ];
        };

        $pageHost = \App\Section\BlockBuilderHost::PAGE;

        $sectionLabels = [];
        foreach ($sectionManager->palette($pageHost) as $p) {
            $sectionLabels[$p['key']] = $p['label'];
        }

        $pageBuilderPayload = static function (int $pageId) use ($pageSections, $sectionManager, $sectionLabels, $pageHost): array {
            $palette = $sectionManager->palette($pageHost);
            $icons = [];
            foreach ($palette as $p) {
                $icons[$p['key']] = $p['icon'];
            }

            return [
                'page_builder_section_rows' => $pageSections->listForPage($pageId),
                'page_builder_section_palette' => $palette,
                'page_builder_section_palette_grouped' => $sectionManager->paletteGrouped($pageHost),
                'page_builder_section_icons' => $icons,
                'page_builder_section_labels' => $sectionLabels,
            ];
        };

        $redirectPageEditBuilder = static function (Request $request, int $id): string {
            return RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.pages.edit', ['id' => (string) $id]) . '#page-builder';
        };

        $redirectAfterBuilderAction = static function (Request $request, int $id) use ($redirectPageEditBuilder): string {
            $q = $request->getQueryParams();
            if (($q['embed'] ?? '') === 'standalone') {
                return RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.pages.builder', ['id' => (string) $id]);
            }

            return $redirectPageEditBuilder($request, $id);
        };

        $capturePageRevision = static function (Page $page, ?int $uid) use ($revisions, $pageSections): void {
            $sectionsJson = json_encode(
                $page->id > 0 ? $pageSections->exportBlocksForPage($page->id) : [],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
            );
            $revisions->captureFromPage($page, $uid, $sectionsJson);
        };

        $restoreSectionsFromRevision = static function (int $pageId, ?string $sectionsJson) use ($pageSections, $sectionValidator): void {
            if ($sectionsJson === null) {
                return;
            }
            if (trim($sectionsJson) === '') {
                $pageSections->replaceAllForPage($pageId, []);

                return;
            }
            try {
                $blocks = json_decode($sectionsJson, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return;
            }
            if (!is_array($blocks)) {
                return;
            }
            usort(
                $blocks,
                static fn (array $a, array $b): int => ((int) ($a['sort_order'] ?? 0)) <=> ((int) ($b['sort_order'] ?? 0))
            );
            $validated = [];
            foreach ($blocks as $block) {
                if (!is_array($block)) {
                    continue;
                }
                $key = trim((string) ($block['type'] ?? $block['section_key'] ?? ''));
                if ($key === '') {
                    continue;
                }
                $data = isset($block['data']) && is_array($block['data']) ? $block['data'] : [];
                $opts = isset($block['options']) && is_array($block['options']) ? $block['options'] : [];
                $r = $sectionValidator->validate($key, $data, $opts);
                if ($r['errors'] !== []) {
                    continue;
                }
                $validated[] = [
                    'type' => $key,
                    'data' => $r['data'],
                    'options' => $r['options'],
                ];
            }
            $pageSections->replaceAllForPage($pageId, $validated);
        };

        $wantsJsonResponse = static function (Request $request): bool {
            $q = $request->getQueryParams();
            if (($q['_format'] ?? '') === 'json') {
                return true;
            }

            return str_contains(strtolower($request->getHeaderLine('Accept')), 'application/json');
        };

        $wantsPartialHtml = static function (Request $request): bool {
            $q = $request->getQueryParams();

            return ($q['_format'] ?? '') === 'partial';
        };

        $sectionPreviewText = static function (array $data): string {
            foreach (['headline', 'title', 'eyebrow'] as $key) {
                if (isset($data[$key]) && is_string($data[$key]) && trim($data[$key]) !== '') {
                    return trim($data[$key]);
                }
            }

            return '';
        };

        $group->get('/pages', function (Request $request, Response $response) use ($twig, $adminContext, $withCmsUser, $repo): Response {
            return $twig->render($response, 'admin/pages/list.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'pages',
                'pages' => $repo->allOrderedByUpdated(),
            ])));
        })->setName('admin.pages.index');

        $group->get('/pages/new', function (Request $request, Response $response) use ($twig, $adminContext, $withCmsUser, $pageFormMediaPicker, $pageFormRevisionSidebar, $pageFormFeaturedThumb, $mediaRepo): Response {
            return $twig->render($response, 'admin/pages/form.twig', $withCmsUser($request, array_merge($adminContext(), $pageFormMediaPicker($request), $pageFormRevisionSidebar(null), $pageFormFeaturedThumb(null, null), [
                'admin_nav' => 'pages',
                'form_mode' => 'create',
                'page' => null,
                'errors' => [],
                'seo_media_select' => $mediaRepo->listImagesForPicker(200),
                'old' => [
                    'title' => '',
                    'slug' => '',
                    'seo_title' => '',
                    'seo_description' => '',
                    'tags' => '',
                    'content' => '',
                    'status' => 'draft',
                    'featured_image_id' => null,
                    'featured_image_id_typed' => '',
                ],
                'workflow_statuses' => WorkflowService::STATUSES,
            ])));
        })->setName('admin.pages.new');

        $previewResponse = static function (
            Response $response,
            Twig $twig,
            callable $viewData,
            \App\Page\Page $previewPage,
            bool $hasSections,
            string $sectionsHtml
        ) use ($pdo): Response {
            $featuredUrl = '';
            if ($previewPage->featuredImageId !== null) {
                $featuredUrl = (new MediaUrlHelper($pdo))->pathForId($previewPage->featuredImageId);
            }
            $vd = $viewData();
            $siteUrl = rtrim((string) ($vd['site_url'] ?? ''), '/');
            $seoSvc = new SeoService(new MediaUrlHelper($pdo));
            $seoTwig = MetaTagBuilder::twigVars($seoSvc->resolveForPage(
                $previewPage,
                '/p/' . $previewPage->slug,
                $siteUrl,
                Settings::get('site_name') ?: null
            ));
            $previewBody = ExternalLinkPolicy::maybeNofollowExternalAnchorsInHtml($previewPage->content);
            $cmsPageView = $previewBody === $previewPage->content ? $previewPage : $previewPage->withContent($previewBody);
            $html = $twig->fetch('page/show.twig', array_merge($vd, $seoTwig, [
                'cms_page' => $cmsPageView,
                'cms_page_preview' => true,
                'cms_page_has_sections' => $hasSections,
                'cms_page_sections_html' => $sectionsHtml,
                'cms_page_featured_url' => $featuredUrl,
            ]));
            $response->getBody()->write($html);

            return $response
                ->withHeader('Content-Type', 'text/html; charset=utf-8')
                ->withHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
                ->withHeader('Cache-Control', 'private, no-store, must-revalidate')
                ->withHeader('Pragma', 'no-cache');
        };

        $group->post('/pages/preview', function (Request $request, Response $response) use ($twig, $viewData, $previewResponse): Response {
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $previewPage = PagePreviewFactory::fromPostBody($body, null);

            return $previewResponse($response, $twig, $viewData, $previewPage, false, '');
        })->setName('admin.pages.preview_new');

        $group->post('/pages/{id:[0-9]+}/preview', function (Request $request, Response $response, array $args) use ($twig, $viewData, $repo, $pageSections, $sectionRenderer, $previewResponse): Response {
            $id = (int) $args['id'];
            $existing = $repo->findById($id);
            if ($existing === null) {
                throw new HttpNotFoundException($request);
            }
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $previewPage = PagePreviewFactory::fromPostBody($body, $existing);
            $rows = $pageSections->listForPage($id);
            $sectionsHtml = $rows !== [] ? $sectionRenderer->renderPage($twig->getEnvironment(), $rows) : '';

            return $previewResponse($response, $twig, $viewData, $previewPage, $rows !== [], $sectionsHtml);
        })->setName('admin.pages.preview');

        $group->post('/pages/new', function (Request $request, Response $response) use ($twig, $adminContext, $withCmsUser, $repo, $revisions, $validator, $workflow, $activity, $cmsUid, $pageFormMediaPicker, $pageFormRevisionSidebar, $appendFeaturedMediaError, $pageFormFeaturedThumb, $mediaRepo, $mergePageSeoOld, $viewData): Response {
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $result = $appendFeaturedMediaError($validator->validate($body));
            $seoParsed = SeoFormParser::parse($body, $mediaRepo);
            $result['errors'] = array_merge($result['errors'], $seoParsed['errors']);
            /** @var array<string, mixed> $cmsUser */
            $cmsUser = $request->getAttribute('cms_user') ?? [];
            $perms = $cmsUser['permission_slugs'] ?? [];

            if ($result['errors'] === [] && !$workflow->canTransition($perms, 'draft', $result['values']['status'])) {
                $result['errors']['status'] = 'You cannot set this status.';
            }

            if ($result['errors'] !== []) {
                return $twig->render($response, 'admin/pages/form.twig', $withCmsUser($request, array_merge($adminContext(), $pageFormMediaPicker($request), $pageFormRevisionSidebar(null), $pageFormFeaturedThumb(null, $mergePageSeoOld($result['values'], $body)), [
                    'admin_nav' => 'pages',
                    'form_mode' => 'create',
                    'page' => null,
                    'errors' => $result['errors'],
                    'old' => $mergePageSeoOld($result['values'], $body),
                    'seo_media_select' => $mediaRepo->listImagesForPicker(200),
                    'workflow_statuses' => WorkflowService::STATUSES,
                ])));
            }

            $v = $result['values'];
            $san = PageContentSanitizer::fromEnv();
            $rawHtml = $v['content'];
            $v['content'] = $san->sanitize($rawHtml);

            $slug = $v['slug'];
            if ($slug === '') {
                $slug = PageSlugger::slugify($v['title']);
            }
            $slug = PageSlugger::ensureUnique($repo, $slug, null);

            $seoTitle = $v['seo_title'] !== '' ? $v['seo_title'] : null;
            $seoDesc = $v['seo_description'] !== '' ? $v['seo_description'] : null;
            $tagsJson = PageTagParser::toJson(PageTagParser::parseCommaSeparated($v['tags']));
            $newId = $repo->insert(
                $v['title'],
                $slug,
                $seoTitle,
                $seoDesc,
                $seoParsed['focus_keyphrase'],
                $tagsJson,
                $v['featured_image_id'],
                $seoParsed['canonical_url'],
                $seoParsed['seo_noindex'],
                $seoParsed['og_title'],
                $seoParsed['og_description'],
                $seoParsed['og_image_id'],
                $seoParsed['twitter_title'],
                $seoParsed['twitter_description'],
                $seoParsed['twitter_image_id'],
                $seoParsed['schema_json'],
                $v['content'],
                $v['status'],
                $v['published_at'] ?? null,
                $v['scheduled_publish_at'] ?? null,
                $v['scheduled_unpublish_at'] ?? null
            );
            $page = $repo->findById($newId);
            if ($page !== null) {
                $capturePageRevision($page, $cmsUid($request));
            }
            $activity->log($cmsUid($request), 'page.created', 'page', $newId, ['title' => $v['title']]);
            Events::dispatch(new StorefrontCachesInvalidateEvent('page_created'));
            $parser = RouteContext::fromRequest($request)->getRouteParser();
            $siteUrl = (string) (($viewData())['site_url'] ?? '');
            if (AfterSaveRedirect::wantsPublicView($body)) {
                $viewUrl = AfterSaveRedirect::pagePublicUrl($siteUrl, $slug, $v['status'], $newId, $v['published_at'] ?? null);
                if ($viewUrl !== null) {
                    Flash::set('success', 'Page created.');

                    return $response->withHeader('Location', $viewUrl)->withStatus(302);
                }
                Flash::set('success', 'Page created. Publish it to view on the site (or set it as the homepage).');
            } else {
                Flash::set('success', 'Page created.');
            }

            return $response
                ->withHeader('Location', $parser->urlFor('admin.pages.edit', ['id' => (string) $newId]))
                ->withStatus(302);
        })->setName('admin.pages.store');

        $group->get('/pages/{id:[0-9]+}/edit', function (Request $request, Response $response, array $args) use ($twig, $adminContext, $withCmsUser, $repo, $workflow, $pageFormMediaPicker, $pageFormRevisionSidebar, $pageFormFeaturedThumb, $mediaRepo, $pageBuilderPayload, $editSessions, $cmsUid): Response {
            $id = (int) $args['id'];
            $page = $repo->findById($id);
            if ($page === null) {
                throw new HttpNotFoundException($request);
            }
            /** @var array<string, mixed> $cmsUser */
            $cmsUser = $request->getAttribute('cms_user') ?? [];
            $perms = $cmsUser['permission_slugs'] ?? [];
            $uid = $cmsUid($request) ?? 0;
            $editSession = $uid > 0
                ? $editSessions->forEditForm(EditSubjectType::PAGE, $id, $page->updatedAt, $uid)
                : [];

            return $twig->render($response, 'admin/pages/form.twig', $withCmsUser($request, array_merge($adminContext(), $pageFormMediaPicker($request), $pageFormRevisionSidebar($id), $pageFormFeaturedThumb($page, null), $pageBuilderPayload($id), $editSession, [
                'edit_form_id' => 'page-edit-form',
                'admin_nav' => 'pages',
                'form_mode' => 'edit',
                'page' => $page,
                'errors' => [],
                'old' => null,
                'seo_media_select' => $mediaRepo->listImagesForPicker(200),
                'workflow_statuses' => $workflow->allowedTargets($perms, $page->status),
            ])));
        })->setName('admin.pages.edit');

        $group->post('/pages/{id:[0-9]+}/edit', function (Request $request, Response $response, array $args) use ($twig, $adminContext, $withCmsUser, $repo, $revisions, $validator, $workflow, $activity, $cmsUid, $pageFormMediaPicker, $pageFormRevisionSidebar, $appendFeaturedMediaError, $pageFormFeaturedThumb, $mediaRepo, $mergePageSeoOld, $pdo, $viewData, $pageBuilderPayload, $editSessions): Response {
            $id = (int) $args['id'];
            $page = $repo->findById($id);
            if ($page === null) {
                throw new HttpNotFoundException($request);
            }

            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $result = $appendFeaturedMediaError($validator->validate($body));
            $seoParsed = SeoFormParser::parse($body, $mediaRepo);
            $result['errors'] = array_merge($result['errors'], $seoParsed['errors']);
            /** @var array<string, mixed> $cmsUser */
            $cmsUser = $request->getAttribute('cms_user') ?? [];
            $perms = $cmsUser['permission_slugs'] ?? [];

            if ($result['errors'] === [] && !$workflow->canTransition($perms, $page->status, $result['values']['status'])) {
                $result['errors']['status'] = 'You cannot change status to this value for your role.';
            }

            if ($result['errors'] !== []) {
                return $twig->render($response, 'admin/pages/form.twig', $withCmsUser($request, array_merge($adminContext(), $pageFormMediaPicker($request), $pageFormRevisionSidebar($id), $pageFormFeaturedThumb($page, $mergePageSeoOld($result['values'], $body)), $pageBuilderPayload($id), [
                    'admin_nav' => 'pages',
                    'form_mode' => 'edit',
                    'page' => $page,
                    'errors' => $result['errors'],
                    'old' => $mergePageSeoOld($result['values'], $body),
                    'seo_media_select' => $mediaRepo->listImagesForPicker(200),
                    'workflow_statuses' => $workflow->allowedTargets($perms, $page->status),
                ])));
            }

            $v = $result['values'];
            $san = PageContentSanitizer::fromEnv();
            $rawHtml = $v['content'];
            $v['content'] = $san->sanitize($rawHtml);

            $slug = $v['slug'];
            if ($slug === '') {
                $slug = PageSlugger::slugify($v['title']);
            }
            $slug = PageSlugger::ensureUnique($repo, $slug, $id);

            $oldSlug = $page->slug;
            $capturePageRevision($page, $cmsUid($request));
            $seoTitle = $v['seo_title'] !== '' ? $v['seo_title'] : null;
            $seoDesc = $v['seo_description'] !== '' ? $v['seo_description'] : null;
            $tagsJson = PageTagParser::toJson(PageTagParser::parseCommaSeparated($v['tags']));
            $repo->update(
                $id,
                $v['title'],
                $slug,
                $seoTitle,
                $seoDesc,
                $seoParsed['focus_keyphrase'],
                $tagsJson,
                $v['featured_image_id'],
                $seoParsed['canonical_url'],
                $seoParsed['seo_noindex'],
                $seoParsed['og_title'],
                $seoParsed['og_description'],
                $seoParsed['og_image_id'],
                $seoParsed['twitter_title'],
                $seoParsed['twitter_description'],
                $seoParsed['twitter_image_id'],
                $seoParsed['schema_json'],
                $v['content'],
                $v['status'],
                $v['published_at'] ?? null,
                $v['scheduled_publish_at'] ?? null,
                $v['scheduled_unpublish_at'] ?? null,
                $cmsUid($request)
            );
            if ($page->status === 'published' && $oldSlug !== $slug) {
                $base = rtrim((string) ($viewData()['site_url'] ?? ''), '/');
                (new RedirectRepository($pdo))->upsertPath('/p/' . $oldSlug, $base . '/p/' . $slug, 301);
            }
            $activity->log($cmsUid($request), 'page.updated', 'page', $id, ['title' => $v['title']]);
            Events::dispatch(new StorefrontCachesInvalidateEvent('page_updated'));
            $saveUid = $cmsUid($request);
            if ($saveUid !== null) {
                $editSessions->clearAfterSave(EditSubjectType::PAGE, $id, $saveUid);
            }
            $parser = RouteContext::fromRequest($request)->getRouteParser();
            $siteUrl = (string) (($viewData())['site_url'] ?? '');
            if (AfterSaveRedirect::wantsPublicView($body)) {
                $viewUrl = AfterSaveRedirect::pagePublicUrl($siteUrl, $slug, $v['status'], $id, $v['published_at'] ?? null);
                if ($viewUrl !== null) {
                    Flash::set('success', 'Page updated.');

                    return $response->withHeader('Location', $viewUrl)->withStatus(302);
                }
                Flash::set('success', 'Page saved. Publish it to view on the site.');
            } else {
                Flash::set('success', 'Page updated.');
            }

            return $response
                ->withHeader('Location', $parser->urlFor('admin.pages.edit', ['id' => (string) $id]))
                ->withStatus(302);
        })->setName('admin.pages.update');

        $group->post('/pages/{id:[0-9]+}/delete', function (Request $request, Response $response, array $args) use ($repo, $activity, $cmsUid): Response {
            $id = (int) $args['id'];
            $page = $repo->findById($id);
            if ($page === null) {
                throw new HttpNotFoundException($request);
            }
            $repo->trash($id, $cmsUid($request));
            $activity->log($cmsUid($request), 'page.trashed', 'page', $id, ['title' => $page->title]);
            Flash::set('success', 'Page moved to trash.');
            Events::dispatch(new StorefrontCachesInvalidateEvent('page_trashed'));

            return $response
                ->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.pages.index'))
                ->withStatus(302);
        })->setName('admin.pages.delete')->add($permDelete);

        $group->get('/pages/{id:[0-9]+}/revisions', function (Request $request, Response $response, array $args) use ($twig, $adminContext, $withCmsUser, $repo, $revisions): Response {
            $id = (int) $args['id'];
            $page = $repo->findById($id);
            if ($page === null) {
                throw new HttpNotFoundException($request);
            }
            $rows = $revisions->listForPage($id);
            foreach ($rows as &$row) {
                $row['section_count'] = PageRevisionRepository::sectionCountFromJson(
                    isset($row['sections_json']) ? (string) $row['sections_json'] : null
                );
            }
            unset($row);

            return $twig->render($response, 'admin/pages/revisions.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'pages',
                'page' => $page,
                'revision_rows' => $rows,
            ])));
        })->setName('admin.pages.revisions');

        $group->get('/pages/{id:[0-9]+}/revisions/compare/{revId:[0-9]+}', function (Request $request, Response $response, array $args) use ($twig, $adminContext, $withCmsUser, $repo, $revisions, $pageSections): Response {
            $id = (int) $args['id'];
            $revId = (int) $args['revId'];
            $page = $repo->findById($id);
            $rev = $revisions->findById($revId);
            if ($page === null || $rev === null || (int) $rev['page_id'] !== $id) {
                throw new HttpNotFoundException($request);
            }

            $revTagsJson = isset($rev['tags_json']) && $rev['tags_json'] !== null && $rev['tags_json'] !== ''
                ? (string) $rev['tags_json']
                : null;

            $q = $request->getQueryParams();
            $otherRaw = isset($q['other']) ? (string) $q['other'] : '';
            $otherId = ctype_digit($otherRaw) ? (int) $otherRaw : 0;
            $revRight = null;
            $revRightTagsDisplay = '';
            if ($otherId > 0 && $otherId !== $revId) {
                $revRight = $revisions->findById($otherId);
                if ($revRight === null || (int) $revRight['page_id'] !== $id) {
                    throw new HttpNotFoundException($request);
                }
                $rtj = $revRight['tags_json'] ?? null;
                $revRightTagsDisplay = PageTagParser::slugsToEditString(PageTagParser::fromJson(
                    $rtj !== null && $rtj !== '' ? (string) $rtj : null
                ));
            }

            $leftContent = (string) $rev['content'];
            $rightContent = $revRight !== null ? (string) $revRight['content'] : $page->content;
            $unifiedDiff = implode("\n", LineDiff::unified($leftContent, $rightContent));

            $revSectionCount = PageRevisionRepository::sectionCountFromJson(
                isset($rev['sections_json']) ? (string) $rev['sections_json'] : null
            );
            $rightSectionCount = $revRight !== null
                ? PageRevisionRepository::sectionCountFromJson(
                    isset($revRight['sections_json']) ? (string) $revRight['sections_json'] : null
                )
                : $pageSections->countForPage($id);

            return $twig->render($response, 'admin/pages/revision_compare.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'pages',
                'page' => $page,
                'revision' => $rev,
                'revision_right' => $revRight,
                'revision_tags_display' => PageTagParser::slugsToEditString(PageTagParser::fromJson($revTagsJson)),
                'revision_right_tags_display' => $revRightTagsDisplay,
                'revision_unified_diff' => $unifiedDiff,
                'revision_section_count' => $revSectionCount,
                'revision_right_section_count' => $rightSectionCount,
            ])));
        })->setName('admin.pages.revision_compare');

        $group->post('/pages/{id:[0-9]+}/preview-link', function (Request $request, Response $response, array $args) use ($repo, $pdo, $cmsUid, $viewData): Response {
            $id = (int) $args['id'];
            $page = $repo->findById($id);
            if ($page === null) {
                throw new HttpNotFoundException($request);
            }
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $ttlChoices = [3600 => true, 86400 => true, 604800 => true];
            $ttlRaw = (string) ($body['preview_link_ttl'] ?? '86400');
            $ttl = ctype_digit($ttlRaw) ? (int) $ttlRaw : 86400;
            if (!isset($ttlChoices[$ttl])) {
                $ttl = 86400;
            }
            $plain = (new PreviewTokenRepository($pdo))->mint('page', $id, $ttl, $cmsUid($request));
            $parser = RouteContext::fromRequest($request)->getRouteParser();
            $site = rtrim((string) (($viewData())['site_url'] ?? ''), '/');
            $path = $parser->urlFor('public.preview.page', ['id' => (string) $id], ['token' => $plain]);
            $url = $site !== '' ? ($site . $path) : $path;
            Flash::set('success', 'Stakeholder preview link (copy now; expires automatically): ' . $url);

            return $response
                ->withHeader('Location', $parser->urlFor('admin.pages.edit', ['id' => (string) $id]))
                ->withStatus(302);
        })->setName('admin.pages.preview_link');

        $group->post('/pages/{id:[0-9]+}/revisions/{revId:[0-9]+}/restore', function (Request $request, Response $response, array $args) use ($repo, $revisions, $workflow, $activity, $cmsUid, $mediaRepo, $capturePageRevision, $restoreSectionsFromRevision): Response {
            $id = (int) $args['id'];
            $revId = (int) $args['revId'];
            $page = $repo->findById($id);
            $rev = $revisions->findById($revId);
            if ($page === null || $rev === null || (int) $rev['page_id'] !== $id) {
                throw new HttpNotFoundException($request);
            }
            /** @var array<string, mixed> $cmsUser */
            $cmsUser = $request->getAttribute('cms_user') ?? [];
            $perms = $cmsUser['permission_slugs'] ?? [];
            $targetStatus = (string) $rev['status'];
            if (!$workflow->canTransition($perms, $page->status, $targetStatus)) {
                Flash::set('error', 'You cannot restore to this status.');

                return $response
                    ->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.pages.revisions', ['id' => (string) $id]))
                    ->withStatus(302);
            }

            $capturePageRevision($page, $cmsUid($request));
            $restoredBody = PageContentSanitizer::fromEnv()->sanitize((string) $rev['content']);
            $revSeoT = isset($rev['seo_title']) && (string) $rev['seo_title'] !== '' ? (string) $rev['seo_title'] : null;
            $revSeoD = isset($rev['seo_description']) && (string) $rev['seo_description'] !== '' ? (string) $rev['seo_description'] : null;
            $revFocusKp = isset($rev['focus_keyphrase']) && (string) $rev['focus_keyphrase'] !== '' ? (string) $rev['focus_keyphrase'] : null;
            $revTags = $rev['tags_json'] ?? null;
            $revTagsJson = PageTagParser::toJson(PageTagParser::fromJson($revTags !== null && $revTags !== '' ? (string) $revTags : null));
            $revFid = isset($rev['featured_image_id']) && $rev['featured_image_id'] !== null && $rev['featured_image_id'] !== ''
                ? (int) $rev['featured_image_id'] : null;
            if ($revFid !== null && !$mediaRepo->isImageId($revFid)) {
                $revFid = null;
            }
            $revCanon = isset($rev['canonical_url']) && (string) $rev['canonical_url'] !== '' ? (string) $rev['canonical_url'] : null;
            $revNoindex = !empty($rev['seo_noindex']);
            $revOgT = isset($rev['og_title']) && (string) $rev['og_title'] !== '' ? (string) $rev['og_title'] : null;
            $revOgD = isset($rev['og_description']) && (string) $rev['og_description'] !== '' ? (string) $rev['og_description'] : null;
            $revOgImg = isset($rev['og_image_id']) && $rev['og_image_id'] !== null && $rev['og_image_id'] !== '' ? (int) $rev['og_image_id'] : null;
            $revTwT = isset($rev['twitter_title']) && (string) $rev['twitter_title'] !== '' ? (string) $rev['twitter_title'] : null;
            $revTwD = isset($rev['twitter_description']) && (string) $rev['twitter_description'] !== '' ? (string) $rev['twitter_description'] : null;
            $revTwImg = isset($rev['twitter_image_id']) && $rev['twitter_image_id'] !== null && $rev['twitter_image_id'] !== '' ? (int) $rev['twitter_image_id'] : null;
            $revSchema = isset($rev['schema_json']) && (string) $rev['schema_json'] !== '' ? (string) $rev['schema_json'] : null;
            if ($revOgImg !== null && !$mediaRepo->isImageId($revOgImg)) {
                $revOgImg = null;
            }
            if ($revTwImg !== null && !$mediaRepo->isImageId($revTwImg)) {
                $revTwImg = null;
            }
            $revPublishedAt = isset($rev['published_at']) && $rev['published_at'] !== null && (string) $rev['published_at'] !== ''
                ? (string) $rev['published_at'] : null;
            $revSchedPub = isset($rev['scheduled_publish_at']) && $rev['scheduled_publish_at'] !== null && (string) $rev['scheduled_publish_at'] !== ''
                ? (string) $rev['scheduled_publish_at'] : null;
            $revSchedUnpub = isset($rev['scheduled_unpublish_at']) && $rev['scheduled_unpublish_at'] !== null && (string) $rev['scheduled_unpublish_at'] !== ''
                ? (string) $rev['scheduled_unpublish_at'] : null;
            $repo->update(
                $id,
                (string) $rev['title'],
                (string) $rev['slug'],
                $revSeoT,
                $revSeoD,
                $revFocusKp,
                $revTagsJson,
                $revFid,
                $revCanon,
                $revNoindex,
                $revOgT,
                $revOgD,
                $revOgImg,
                $revTwT,
                $revTwD,
                $revTwImg,
                $revSchema,
                $restoredBody,
                $targetStatus,
                $revPublishedAt,
                $revSchedPub,
                $revSchedUnpub,
                $cmsUid($request)
            );
            if (array_key_exists('sections_json', $rev) && $rev['sections_json'] !== null) {
                $restoreSectionsFromRevision($id, (string) $rev['sections_json']);
            }
            $activity->log($cmsUid($request), 'page.revision_restored', 'page', $id, ['revision_id' => $revId]);
            Flash::set('success', 'Revision restored.');
            Events::dispatch(new StorefrontCachesInvalidateEvent('page_revision_restored'));

            return $response
                ->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.pages.edit', ['id' => (string) $id]))
                ->withStatus(302);
        })->setName('admin.pages.revision_restore');

        $group->get('/pages/{id:[0-9]+}/builder/section/{sectionId:[0-9]+}', function (Request $request, Response $response, array $args) use (
            $twig,
            $adminContext,
            $withCmsUser,
            $repo,
            $pageSections,
            $sectionManager,
            $mediaRepo,
            $wantsPartialHtml
        ): Response {
            $id = (int) $args['id'];
            $sectionId = (int) $args['sectionId'];
            $page = $repo->findById($id);
            if ($page === null) {
                throw new HttpNotFoundException($request);
            }
            $row = $pageSections->findById($sectionId);
            if ($row === null || $row->pageId !== $id) {
                throw new HttpNotFoundException($request);
            }
            $def = $sectionManager->definition($row->sectionKey);
            if ($def === null) {
                throw new HttpNotFoundException($request);
            }

            $payload = array_merge($adminContext(), [
                'admin_nav' => 'pages',
                'page' => $page,
                'section_row' => $row,
                'section_def' => $def,
                'errors' => [],
                'media_picker_images' => $mediaRepo->listImagesForPicker(200),
                'section_save_url' => RouteContext::fromRequest($request)->getRouteParser()->urlFor(
                    'admin.pages.builder.section_save',
                    ['id' => (string) $id, 'sectionId' => (string) $sectionId]
                ) . '?_format=json',
            ]);

            if ($wantsPartialHtml($request)) {
                return $twig->render($response, 'admin/pages/_builder_section_drawer_body.twig', $withCmsUser($request, $payload));
            }

            return $twig->render($response, 'admin/pages/builder_section.twig', $withCmsUser($request, $payload));
        })->setName('admin.pages.builder.section_edit');

        $group->post('/pages/{id:[0-9]+}/builder/section/{sectionId:[0-9]+}', function (Request $request, Response $response, array $args) use (
            $twig,
            $adminContext,
            $withCmsUser,
            $repo,
            $pageSections,
            $sectionManager,
            $sectionValidator,
            $mediaRepo,
            $redirectPageEditBuilder,
            $wantsJsonResponse,
            $wantsPartialHtml,
            $sectionPreviewText
        ): Response {
            $id = (int) $args['id'];
            $sectionId = (int) $args['sectionId'];
            $page = $repo->findById($id);
            if ($page === null) {
                throw new HttpNotFoundException($request);
            }
            $row = $pageSections->findById($sectionId);
            if ($row === null || $row->pageId !== $id) {
                throw new HttpNotFoundException($request);
            }
            $def = $sectionManager->definition($row->sectionKey);
            if ($def === null) {
                throw new HttpNotFoundException($request);
            }

            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $data = isset($body['data']) && is_array($body['data']) ? $body['data'] : [];
            $options = isset($body['options']) && is_array($body['options']) ? $body['options'] : [];

            $result = $sectionValidator->validate($row->sectionKey, $data, $options);
            $saveUrl = RouteContext::fromRequest($request)->getRouteParser()->urlFor(
                'admin.pages.builder.section_save',
                ['id' => (string) $id, 'sectionId' => (string) $sectionId]
            ) . '?_format=json';

            if ($result['errors'] !== []) {
                $patched = new PageSection(
                    $row->id,
                    $row->pageId,
                    $row->sortOrder,
                    $row->sectionKey,
                    array_merge($row->data, $data),
                    array_merge($row->options, $options)
                );

                $payload = array_merge($adminContext(), [
                    'admin_nav' => 'pages',
                    'page' => $page,
                    'section_row' => $patched,
                    'section_def' => $def,
                    'errors' => $result['errors'],
                    'media_picker_images' => $mediaRepo->listImagesForPicker(200),
                    'section_save_url' => $saveUrl,
                ]);

                if ($wantsJsonResponse($request) || $wantsPartialHtml($request)) {
                    $html = $twig->getEnvironment()->render('admin/pages/_builder_section_drawer_body.twig', $withCmsUser($request, $payload));
                    $response->getBody()->write(json_encode([
                        'ok' => false,
                        'errors' => $result['errors'],
                        'html' => $html,
                    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

                    return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
                }

                return $twig->render($response, 'admin/pages/builder_section.twig', $withCmsUser($request, $payload));
            }

            $pageSections->update($sectionId, $row->sortOrder, $row->sectionKey, $result['data'], $result['options']);
            Events::dispatch(new StorefrontCachesInvalidateEvent('page_section_saved'));

            if ($wantsJsonResponse($request)) {
                $response->getBody()->write(json_encode([
                    'ok' => true,
                    'preview' => $sectionPreviewText($result['data']),
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

                return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
            }

            Flash::set('success', 'Section saved.');

            return $response
                ->withHeader('Location', $redirectPageEditBuilder($request, $id))
                ->withStatus(302);
        })->setName('admin.pages.builder.section_save');

        $group->get('/pages/{id:[0-9]+}/builder', function (Request $request, Response $response, array $args) use (
            $twig,
            $adminContext,
            $withCmsUser,
            $repo,
            $pageSections,
            $sectionManager,
            $sectionLabels
        ): Response {
            $id = (int) $args['id'];
            $page = $repo->findById($id);
            if ($page === null) {
                throw new HttpNotFoundException($request);
            }

            return $twig->render($response, 'admin/pages/builder.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'pages',
                'page' => $page,
                'section_rows' => $pageSections->listForPage($id),
                'section_palette' => $sectionManager->palette($pageHost),
                'section_palette_grouped' => $sectionManager->paletteGrouped($pageHost),
                'section_labels' => $sectionLabels,
                'section_icons' => array_column($sectionManager->palette($pageHost), 'icon', 'key'),
                'builder_panel_standalone' => true,
            ])));
        })->setName('admin.pages.builder');

        $group->post('/pages/{id:[0-9]+}/builder', function (Request $request, Response $response, array $args) use (
            $repo,
            $pageSections,
            $sectionManager,
            $redirectAfterBuilderAction
        ): Response {
            $id = (int) $args['id'];
            $page = $repo->findById($id);
            if ($page === null) {
                throw new HttpNotFoundException($request);
            }
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $action = (string) ($body['_action'] ?? '');
            $url = $redirectAfterBuilderAction($request, $id);

            if ($action === 'add') {
                $key = trim((string) ($body['section_key'] ?? ''));
                if (!$sectionManager->has($key)) {
                    Flash::set('error', 'Unknown section type.');
                } else {
                    $sort = $pageSections->nextSortOrder($id);
                    $pageSections->insert(
                        $id,
                        $sort,
                        $key,
                        $sectionManager->defaultData($key),
                        $sectionManager->defaultOptions($key)
                    );
                    Flash::set('success', 'Section added.');
                    Events::dispatch(new StorefrontCachesInvalidateEvent('page_section_added'));
                }

                return $response->withHeader('Location', $url)->withStatus(302);
            }

            if ($action === 'delete') {
                $sid = isset($body['section_id']) ? (int) $body['section_id'] : 0;
                if ($sid > 0 && $pageSections->belongsToPage($sid, $id)) {
                    $pageSections->delete($sid);
                    Flash::set('success', 'Section removed.');
                    Events::dispatch(new StorefrontCachesInvalidateEvent('page_section_deleted'));
                }

                return $response->withHeader('Location', $url)->withStatus(302);
            }

            if ($action === 'duplicate') {
                $sid = isset($body['section_id']) ? (int) $body['section_id'] : 0;
                $src = $sid > 0 ? $pageSections->findById($sid) : null;
                if ($src !== null && $src->pageId === $id) {
                    $pageSections->insert($id, $pageSections->nextSortOrder($id), $src->sectionKey, $src->data, $src->options);
                    Flash::set('success', 'Section duplicated.');
                    Events::dispatch(new StorefrontCachesInvalidateEvent('page_section_duplicated'));
                }

                return $response->withHeader('Location', $url)->withStatus(302);
            }

            if ($action === 'move') {
                $sid = isset($body['section_id']) ? (int) $body['section_id'] : 0;
                $dir = (string) ($body['direction'] ?? '');
                $rows = $pageSections->listForPage($id);
                $ids = array_map(static fn (PageSection $r): int => $r->id, $rows);
                $idx = array_search($sid, $ids, true);
                if ($idx !== false) {
                    if ($dir === 'up' && $idx > 0) {
                        $tmp = $ids[$idx - 1];
                        $ids[$idx - 1] = $ids[$idx];
                        $ids[$idx] = $tmp;
                        $pageSections->reorderForPage($id, $ids);
                        Flash::set('success', 'Order updated.');
                        Events::dispatch(new StorefrontCachesInvalidateEvent('page_sections_reordered'));
                    } elseif ($dir === 'down' && $idx < count($ids) - 1) {
                        $tmp = $ids[$idx + 1];
                        $ids[$idx + 1] = $ids[$idx];
                        $ids[$idx] = $tmp;
                        $pageSections->reorderForPage($id, $ids);
                        Flash::set('success', 'Order updated.');
                        Events::dispatch(new StorefrontCachesInvalidateEvent('page_sections_reordered'));
                    }
                }

                return $response->withHeader('Location', $url)->withStatus(302);
            }

            if ($action === 'reorder') {
                $order = $body['order'] ?? [];
                $order = is_array($order) ? $order : [];
                $ids = [];
                foreach ($order as $v) {
                    $ids[] = (int) $v;
                }
                $ids = array_values(array_filter($ids, static fn (int $i): bool => $i > 0));
                $saved = false;
                if ($ids !== []) {
                    $pageSections->reorderForPage($id, $ids);
                    $saved = true;
                    Events::dispatch(new StorefrontCachesInvalidateEvent('page_sections_reordered'));
                }

                $wantsJson = str_contains(strtolower($request->getHeaderLine('Accept')), 'application/json')
                    || (string) ($body['_format'] ?? '') === 'json';
                if ($wantsJson) {
                    $response->getBody()->write(json_encode(['ok' => $saved]));

                    return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
                }

                if ($saved) {
                    Flash::set('success', 'Order saved.');
                }

                return $response->withHeader('Location', $url)->withStatus(302);
            }

            return $response->withHeader('Location', $url)->withStatus(302);
        });
    })->add($permPages)->add($middleware);
};
