<?php

declare(strict_types=1);

use App\Api\PublicApiAuthContext;
use App\Api\PublicApiContentRules;
use App\Api\PublicApiEntryPayload;
use App\Api\PublicApiGraphQL;
use App\Api\PublicApiGraphQLContext;
use App\Api\PublicApiKeyRepository;
use App\CmsVersion;
use App\Api\PublicContentApi;
use App\Content\ContentEntryFormValidator;
use App\Content\ContentEntryRepository;
use App\Content\ContentEntryRevisionRepository;
use App\Content\ContentEntryValueRepository;
use App\Content\ContentEntryViewPresenter;
use App\Content\ContentFieldRepository;
use App\Content\ContentSlugger;
use App\Content\ContentTypeRepository;
use App\Content\PublicContentIndexPager;
use App\Content\ReservedContentSlugs;
use App\Event\ContentEntrySavedEvent;
use App\Event\Events;
use App\Http\Middleware\PublicApiKeyMiddleware;
use App\Media\MediaRepository;
use App\Media\MediaUrlHelper;
use App\Page\PageRepository;
use App\Section\PageSectionRepository;
use App\Section\SectionManager;
use App\Section\SectionRenderer;
use App\Section\SectionTemplateResolver;
use App\Seo\RedirectRepository;
use App\Seo\SeoFormParser;
use App\Taxonomy\ContentEntryTaxonomyRepository;
use App\Taxonomy\EntryTaxonomySync;
use App\Taxonomy\EntryTaxonomyValidator;
use App\Taxonomy\TaxonomyRepository;
use App\Taxonomy\TaxonomyTermRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Exception\HttpNotFoundException;
use Slim\Routing\RouteCollectorProxy;
use Slim\Views\Twig;

/**
 * Headless JSON API (/api/v1). Auth: CMS_PUBLIC_API_KEY and/or database keys (Tools → API keys).
 *
 * @param callable(): array<string, mixed> $viewData
 */
return static function (App $app, Twig $twig, \PDO $pdo, callable $viewData): void {
    $types = new ContentTypeRepository($pdo);
    $fields = new ContentFieldRepository($pdo);
    $entries = new ContentEntryRepository($pdo);
    $values = new ContentEntryValueRepository($pdo);
    $mediaUrls = new MediaUrlHelper($pdo);
    $entryTaxonomies = new ContentEntryTaxonomyRepository($pdo);
    $pages = new PageRepository($pdo);
    $taxonomyRepo = new TaxonomyRepository($pdo);
    $taxonomyTermRepo = new TaxonomyTermRepository($pdo);
    $mediaRepo = new MediaRepository($pdo);
    $entryValidator = new ContentEntryFormValidator();
    $entryTaxonomyValidator = new EntryTaxonomyValidator();
    $entryRevRepo = new ContentEntryRevisionRepository($pdo);
    $apiKeyRepo = new PublicApiKeyRepository($pdo);
    $pageSections = new PageSectionRepository($pdo);
    $sectionManager = new SectionManager();
    $sectionRenderer = new SectionRenderer($sectionManager, new SectionTemplateResolver($sectionManager));

    $json = static function (Response $response, array $payload, int $status = 200): Response {
        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $response->withStatus($status)->withHeader('Content-Type', 'application/json; charset=utf-8');
    };

    $siteUrl = static fn (): string => rtrim((string) (($viewData())['site_url'] ?? ''), '/');

    $auth = static function (Request $request): PublicApiAuthContext {
        /** @var mixed $a */
        $a = $request->getAttribute(PublicApiAuthContext::ATTR);
        if (!$a instanceof PublicApiAuthContext) {
            throw new \RuntimeException('Public API auth context missing.');
        }

        return $a;
    };

    $app->group('/api/v1', function (RouteCollectorProxy $group) use (
        $twig,
        $pdo,
        $types,
        $fields,
        $entries,
        $values,
        $mediaUrls,
        $entryTaxonomies,
        $pages,
        $taxonomyRepo,
        $taxonomyTermRepo,
        $mediaRepo,
        $entryValidator,
        $entryTaxonomyValidator,
        $entryRevRepo,
        $json,
        $siteUrl,
        $auth,
        $pageSections,
        $sectionRenderer,
        $viewData
    ): void {
        $group->get('', function (Request $request, Response $response) use ($json): Response {
            return $json($response, [
                'ok' => true,
                'version' => 1,
                'cms_version' => CmsVersion::CURRENT,
                'documentation' => 'JSON API. Authenticate with Authorization: Bearer <key> or X-Api-Key. Env key or database key (prefix.secret). Scopes: read, read_drafts, write.',
                'endpoints' => [
                    'GET /api/v1',
                    'POST /api/v1/graphql',
                    'GET /api/v1/content-types',
                    'GET /api/v1/content-types/{slug}',
                    'GET /api/v1/content-types/{slug}/entries?page=&per_page=&status=',
                    'POST /api/v1/content-types/{slug}/entries',
                    'GET /api/v1/content-types/{slug}/entries/{entrySlug}',
                    'PATCH /api/v1/content-types/{slug}/entries/{entrySlug}',
                    'GET /api/v1/pages',
                    'GET /api/v1/pages/{slug}',
                ],
            ]);
        });

        $group->post('/graphql', function (Request $request, Response $response) use (
            $auth,
            $json,
            $types,
            $fields,
            $entries,
            $values,
            $mediaUrls,
            $entryTaxonomies,
            $pages,
            $twig,
            $pageSections,
            $sectionRenderer,
            $siteUrl,
            $pdo
        ): Response {
            $a = $auth($request);
            if (!$a->can('read')) {
                return $json($response, ['ok' => false, 'error' => 'forbidden', 'message' => 'The read scope is required.'], 403);
            }
            $parsed = $request->getParsedBody();
            $parsed = is_array($parsed) ? $parsed : [];
            $query = isset($parsed['query']) && is_string($parsed['query']) ? $parsed['query'] : '';
            $vars = isset($parsed['variables']) && is_array($parsed['variables']) ? $parsed['variables'] : [];
            $op = isset($parsed['operationName']) && is_string($parsed['operationName']) && $parsed['operationName'] !== ''
                ? $parsed['operationName'] : null;
            $ctx = new PublicApiGraphQLContext(
                $a,
                $siteUrl(),
                $pdo,
                $types,
                $fields,
                $entries,
                $values,
                $mediaUrls,
                $entryTaxonomies,
                $pages,
                $twig->getEnvironment(),
                $pageSections,
                $sectionRenderer
            );
            $out = PublicApiGraphQL::execute($query, $vars, $op, $ctx);

            return $json($response, $out);
        });

        $group->get('/content-types', function (Request $request, Response $response) use ($auth, $types, $json): Response {
            if (!$auth($request)->can('read')) {
                return $json($response, ['ok' => false, 'error' => 'forbidden', 'message' => 'The read scope is required.'], 403);
            }
            $list = [];
            foreach ($types->allOrdered() as $t) {
                $list[] = PublicContentApi::typeSummary($t);
            }

            return $json($response, ['ok' => true, 'data' => $list]);
        });

        $group->get('/content-types/{typeSlug}', function (Request $request, Response $response, array $args) use ($auth, $types, $fields, $json): Response {
            if (!$auth($request)->can('read')) {
                return $json($response, ['ok' => false, 'error' => 'forbidden', 'message' => 'The read scope is required.'], 403);
            }
            $slug = (string) ($args['typeSlug'] ?? '');
            if (ReservedContentSlugs::isReserved($slug)) {
                throw new HttpNotFoundException($request);
            }
            $t = $types->findBySlug($slug);
            if ($t === null) {
                return $json($response, ['ok' => false, 'error' => 'not_found', 'message' => 'Unknown content type.'], 404);
            }
            $fieldList = $fields->forTypeOrdered($t->id);

            return $json($response, ['ok' => true, 'data' => PublicContentApi::typeDetail($t, $fieldList)]);
        });

        $group->get('/content-types/{typeSlug}/entries', function (Request $request, Response $response, array $args) use (
            $auth,
            $types,
            $entries,
            $json,
            $siteUrl
        ): Response {
            if (!$auth($request)->can('read')) {
                return $json($response, ['ok' => false, 'error' => 'forbidden', 'message' => 'The read scope is required.'], 403);
            }
            $slug = (string) ($args['typeSlug'] ?? '');
            if (ReservedContentSlugs::isReserved($slug)) {
                throw new HttpNotFoundException($request);
            }
            $t = $types->findBySlug($slug);
            if ($t === null || !PublicApiContentRules::typeAllowedForRead($t, $auth($request))) {
                return $json($response, ['ok' => false, 'error' => 'not_found', 'message' => 'Unknown type or type is not exposed for this key.'], 404);
            }
            $query = $request->getQueryParams();
            $page = isset($query['page']) && is_numeric($query['page']) ? max(1, (int) $query['page']) : 1;
            $perPage = isset($query['per_page']) && is_numeric($query['per_page']) ? max(1, min(50, (int) $query['per_page'])) : 20;
            $statusParam = isset($query['status']) && is_string($query['status']) ? $query['status'] : 'published';
            $st = PublicApiContentRules::statusesForEntryList($statusParam, $auth($request));
            if (!$st['ok']) {
                $code = $st['code'];

                return $json($response, [
                    'ok' => false,
                    'error' => $st['error'],
                    'message' => $code === 403
                        ? 'This status filter requires the read_drafts scope.'
                        : 'Invalid status. Use published, draft, in_review, approved, archived, or all.',
                ], $code);
            }
            $total = $entries->countForContentTypeWithStatuses($t->id, $st['statuses']);
            $totalPages = max(1, (int) ceil(max(1, $total) / $perPage));
            if ($page > $totalPages) {
                $page = $totalPages;
            }
            $rows = $entries->listForContentTypePagedWithStatuses($t->id, $st['statuses'], $page, $perPage);
            $base = $siteUrl();
            $items = [];
            foreach ($rows as $row) {
                $items[] = PublicContentApi::entrySummary($t, $row, $base);
            }

            return $json($response, [
                'ok' => true,
                'meta' => [
                    'page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'total_pages' => $totalPages,
                    'page_items' => PublicContentIndexPager::pageItems($page, $totalPages),
                ],
                'data' => $items,
            ]);
        });

        $group->post('/content-types/{typeSlug}/entries', function (Request $request, Response $response, array $args) use (
            $auth,
            $types,
            $fields,
            $entries,
            $values,
            $mediaUrls,
            $entryTaxonomies,
            $taxonomyRepo,
            $taxonomyTermRepo,
            $mediaRepo,
            $entryValidator,
            $entryTaxonomyValidator,
            $entryRevRepo,
            $json,
            $siteUrl,
            $pdo
        ): Response {
            $a = $auth($request);
            if (!$a->can('write')) {
                return $json($response, ['ok' => false, 'error' => 'forbidden', 'message' => 'The write scope is required.'], 403);
            }
            $slug = (string) ($args['typeSlug'] ?? '');
            if (ReservedContentSlugs::isReserved($slug)) {
                throw new HttpNotFoundException($request);
            }
            $t = $types->findBySlug($slug);
            if ($t === null || !PublicApiContentRules::typeAllowedForRead($t, $a)) {
                return $json($response, ['ok' => false, 'error' => 'not_found', 'message' => 'Unknown type or type is not exposed for this key.'], 404);
            }
            $parsed = $request->getParsedBody();
            $parsed = is_array($parsed) ? $parsed : [];
            $fieldList = $fields->forTypeOrdered($t->id);
            $taxonomies = $taxonomyRepo->forContentTypeOrdered($t->id);
            $body = PublicApiEntryPayload::toFormBody($parsed, true, null, $fieldList, [], [], $taxonomies);
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
            if ($allErrors !== []) {
                return $json($response, ['ok' => false, 'error' => 'validation_error', 'errors' => $allErrors], 422);
            }
            $v = $result['values'];
            $entrySlug = ContentSlugger::ensureUniqueEntry($entries, $t->id, $v['slug']);
            $eid = $entries->insert(
                $t->id,
                $v['title'],
                $entrySlug,
                $v['status'],
                $v['featured_image_id'],
                $v['seo_title'],
                $v['seo_description'],
                $seoParsed['focus_keyphrase'],
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
                null
            );
            foreach ($fieldList as $f) {
                $values->upsert($eid, $f->id, $v['custom'][$f->id] ?? null);
            }
            EntryTaxonomySync::sync($eid, $taxResult['term_ids'], $entryTaxonomies);
            $row = $entries->fetchRowById($eid);
            if ($row !== null) {
                $entryRevRepo->capture($eid, $row, $values->valuesByFieldIdForEntry($eid), null);
            }
            Events::dispatch(new ContentEntrySavedEvent($eid, $t->id, true));
            $entry = $entries->findById($eid);
            if ($entry === null) {
                return $json($response, ['ok' => false, 'error' => 'server_error', 'message' => 'Entry could not be loaded after create.'], 500);
            }
            $valueMap = $values->valuesByFieldIdForEntry($eid);
            $fieldRows = ContentEntryViewPresenter::buildFieldRows($fieldList, $valueMap, $mediaUrls, $pdo, rtrim($siteUrl(), '/'));
            $featured = PublicContentApi::featuredImageUrlForEntry($entry, $fieldList, $valueMap, $mediaUrls);
            $groups = $entryTaxonomies->termsGroupedForEntry($eid);
            $data = PublicContentApi::entryDetail($t, $entry, $fieldRows, $groups, $featured !== '' ? $featured : null, $siteUrl());

            return $json($response, ['ok' => true, 'data' => $data], 201);
        });

        $group->patch('/content-types/{typeSlug}/entries/{entrySlug}', function (Request $request, Response $response, array $args) use (
            $auth,
            $types,
            $fields,
            $entries,
            $values,
            $mediaUrls,
            $entryTaxonomies,
            $taxonomyRepo,
            $taxonomyTermRepo,
            $mediaRepo,
            $entryValidator,
            $entryTaxonomyValidator,
            $entryRevRepo,
            $json,
            $siteUrl,
            $pdo,
            $viewData
        ): Response {
            $a = $auth($request);
            if (!$a->can('write')) {
                return $json($response, ['ok' => false, 'error' => 'forbidden', 'message' => 'The write scope is required.'], 403);
            }
            $typeSlug = (string) ($args['typeSlug'] ?? '');
            $entrySlug = (string) ($args['entrySlug'] ?? '');
            if (ReservedContentSlugs::isReserved($typeSlug)) {
                throw new HttpNotFoundException($request);
            }
            $t = $types->findBySlug($typeSlug);
            if ($t === null || !PublicApiContentRules::typeAllowedForRead($t, $a)) {
                return $json($response, ['ok' => false, 'error' => 'not_found', 'message' => 'Unknown type or type is not exposed for this key.'], 404);
            }
            $entry = $entries->findByTypeAndSlug($t->id, $entrySlug);
            if ($entry === null) {
                return $json($response, ['ok' => false, 'error' => 'not_found', 'message' => 'Entry not found.'], 404);
            }
            $parsed = $request->getParsedBody();
            $parsed = is_array($parsed) ? $parsed : [];
            $fieldList = $fields->forTypeOrdered($t->id);
            $valueMap = $values->valuesByFieldIdForEntry($entry->id);
            $taxonomies = $taxonomyRepo->forContentTypeOrdered($t->id);
            $existingTax = $entryTaxonomies->termIdsByTaxonomyForEntry($entry->id);
            if (!isset($parsed['taxonomies']) && !isset($parsed['taxonomy_terms'])) {
                $parsed = array_merge($parsed, ['taxonomy_terms' => $existingTax, 'taxonomy_terms_submitted' => '1']);
            }
            $body = PublicApiEntryPayload::toFormBody($parsed, false, $entry, $fieldList, $valueMap, $existingTax, $taxonomies);
            if ($t->supportsSeo) {
                $body = PublicApiEntryPayload::mergeSeoFromEntryIfMissing($body, $entry);
            }
            $body = PublicApiEntryPayload::mergeScheduleFromEntryIfMissing($body, $entry);
            $result = $entryValidator->validate($body, $t, $fieldList, $entries, $types, $mediaRepo, $entry->id);
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
            if ($allErrors !== []) {
                return $json($response, ['ok' => false, 'error' => 'validation_error', 'errors' => $allErrors], 422);
            }
            $v = $result['values'];
            $newSlug = ContentSlugger::ensureUniqueEntry($entries, $t->id, $v['slug'], $entry->id);
            $prevRow = $entries->fetchRowById($entry->id);
            if ($prevRow !== null) {
                $entryRevRepo->capture($entry->id, $prevRow, $values->valuesByFieldIdForEntry($entry->id), null);
            }
            $oldSlug = $entry->slug;
            $entries->update(
                $entry->id,
                $v['title'],
                $newSlug,
                $v['status'],
                $v['featured_image_id'],
                $v['seo_title'],
                $v['seo_description'],
                $seoParsed['focus_keyphrase'],
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
            if ($entry->status === 'published' && $t->hasPublicRoute && $oldSlug !== $newSlug) {
                $base = rtrim((string) (($viewData())['site_url'] ?? ''), '/');
                (new RedirectRepository($pdo))->upsertPath(
                    '/' . $t->slug . '/' . $oldSlug,
                    $base . '/' . $t->slug . '/' . $newSlug,
                    301
                );
            }
            foreach ($fieldList as $f) {
                $values->upsert($entry->id, $f->id, $v['custom'][$f->id] ?? null);
            }
            EntryTaxonomySync::sync($entry->id, $taxResult['term_ids'], $entryTaxonomies);
            Events::dispatch(new ContentEntrySavedEvent($entry->id, $t->id, false));
            $entry = $entries->findById($entry->id);
            if ($entry === null) {
                return $json($response, ['ok' => false, 'error' => 'server_error', 'message' => 'Entry missing after update.'], 500);
            }
            $valueMap = $values->valuesByFieldIdForEntry($entry->id);
            $fieldRows = ContentEntryViewPresenter::buildFieldRows($fieldList, $valueMap, $mediaUrls, $pdo, rtrim($siteUrl(), '/'));
            $featured = PublicContentApi::featuredImageUrlForEntry($entry, $fieldList, $valueMap, $mediaUrls);
            $groups = $entryTaxonomies->termsGroupedForEntry($entry->id);
            $data = PublicContentApi::entryDetail($t, $entry, $fieldRows, $groups, $featured !== '' ? $featured : null, $siteUrl());

            return $json($response, ['ok' => true, 'data' => $data]);
        });

        $group->get('/content-types/{typeSlug}/entries/{entrySlug}', function (Request $request, Response $response, array $args) use (
            $auth,
            $types,
            $fields,
            $entries,
            $values,
            $mediaUrls,
            $entryTaxonomies,
            $json,
            $siteUrl,
            $pdo
        ): Response {
            if (!$auth($request)->can('read')) {
                return $json($response, ['ok' => false, 'error' => 'forbidden', 'message' => 'The read scope is required.'], 403);
            }
            $typeSlug = (string) ($args['typeSlug'] ?? '');
            $entrySlug = (string) ($args['entrySlug'] ?? '');
            if (ReservedContentSlugs::isReserved($typeSlug)) {
                throw new HttpNotFoundException($request);
            }
            $t = $types->findBySlug($typeSlug);
            if ($t === null || !PublicApiContentRules::typeAllowedForRead($t, $auth($request))) {
                return $json($response, ['ok' => false, 'error' => 'not_found', 'message' => 'Unknown type or type is not exposed for this key.'], 404);
            }
            $a = $auth($request);
            $entry = $a->can('read_drafts')
                ? $entries->findByTypeAndSlug($t->id, $entrySlug)
                : $entries->findPublishedByTypeSlug($t->id, $entrySlug);
            if ($entry === null) {
                return $json($response, ['ok' => false, 'error' => 'not_found', 'message' => 'Entry not found.'], 404);
            }
            $fieldList = $fields->forTypeOrdered($t->id);
            $valueMap = $values->valuesByFieldIdForEntry($entry->id);
            $fieldRows = ContentEntryViewPresenter::buildFieldRows($fieldList, $valueMap, $mediaUrls, $pdo, rtrim($siteUrl(), '/'));
            $featuredUrl = PublicContentApi::featuredImageUrlForEntry($entry, $fieldList, $valueMap, $mediaUrls);
            $groups = $entryTaxonomies->termsGroupedForEntry($entry->id);
            $data = PublicContentApi::entryDetail($t, $entry, $fieldRows, $groups, $featuredUrl !== '' ? $featuredUrl : null, $siteUrl());

            return $json($response, ['ok' => true, 'data' => $data]);
        });

        $group->get('/pages', function (Request $request, Response $response) use ($auth, $pages, $json, $siteUrl): Response {
            if (!$auth($request)->can('read')) {
                return $json($response, ['ok' => false, 'error' => 'forbidden', 'message' => 'The read scope is required.'], 403);
            }
            $base = $siteUrl();
            $items = [];
            foreach ($pages->publishedForSitemap() as $row) {
                $items[] = PublicContentApi::pageSummary($row, $base);
            }

            return $json($response, ['ok' => true, 'data' => $items]);
        });

        $group->get('/pages/{slug}', function (Request $request, Response $response, array $args) use (
            $auth,
            $twig,
            $pdo,
            $pages,
            $mediaUrls,
            $json,
            $siteUrl,
            $pageSections,
            $sectionRenderer
        ): Response {
            if (!$auth($request)->can('read')) {
                return $json($response, ['ok' => false, 'error' => 'forbidden', 'message' => 'The read scope is required.'], 403);
            }
            $slug = (string) ($args['slug'] ?? '');
            $page = $pages->findPublishedBySlug($slug);
            if ($page === null) {
                return $json($response, ['ok' => false, 'error' => 'not_found', 'message' => 'Page not found.'], 404);
            }
            $rows = $pageSections->listForPage($page->id);
            $sectionsHtml = $rows !== [] ? $sectionRenderer->renderPage($twig->getEnvironment(), $rows) : '';
            $featuredUrl = '';
            if ($page->featuredImageId !== null) {
                $featuredUrl = $mediaUrls->pathForId($page->featuredImageId);
            }
            $data = PublicContentApi::pageDetail($page, $featuredUrl !== '' ? $featuredUrl : null, $sectionsHtml, $siteUrl());

            return $json($response, ['ok' => true, 'data' => $data]);
        });
    })->add(new PublicApiKeyMiddleware($apiKeyRepo));
};
