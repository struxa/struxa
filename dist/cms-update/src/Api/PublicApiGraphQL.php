<?php

declare(strict_types=1);

namespace App\Api;

use App\Content\ContentEntryViewPresenter;
use App\Content\ReservedContentSlugs;
use GraphQL\Error\UserError;
use GraphQL\GraphQL;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Validator\DocumentValidator;
use GraphQL\Validator\Rules\DisableIntrospection;
use GraphQL\Validator\Rules\QueryComplexity;
use GraphQL\Validator\Rules\QueryDepth;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;

/**
 * Read-only GraphQL surface for /api/v1/graphql (requires read scope).
 */
final class PublicApiGraphQL
{
    private static ?Schema $schema = null;

    /**
     * @param array<string, mixed>|null $variableValues
     * @return array<string, mixed>
     */
    public static function execute(
        string $query,
        ?array $variableValues,
        ?string $operationName,
        PublicApiGraphQLContext $ctx,
    ): array {
        if (trim($query) === '') {
            return ['errors' => [['message' => 'Missing GraphQL query.']]];
        }
        if (strlen($query) > self::maxQueryBytes()) {
            return ['errors' => [['message' => 'Query exceeds maximum allowed size.']]];
        }
        try {
            $result = GraphQL::executeQuery(
                self::schema(),
                $query,
                null,
                $ctx,
                $variableValues ?? [],
                $operationName,
                null,
                self::graphqlValidationRules()
            );
        } catch (\Throwable $e) {
            return ['errors' => [['message' => $e->getMessage()]]];
        }

        return $result->toArray();
    }

    private static function maxQueryBytes(): int
    {
        $raw = $_ENV['CMS_GRAPHQL_MAX_QUERY_BYTES'] ?? getenv('CMS_GRAPHQL_MAX_QUERY_BYTES');
        $v = is_string($raw) ? (int) trim($raw) : (is_numeric($raw) ? (int) $raw : 24_000);
        if ($v < 500) {
            $v = 24_000;
        }

        return min(500_000, $v);
    }

    /**
     * @return array<string, \GraphQL\Validator\Rules\ValidationRule>
     */
    private static function graphqlValidationRules(): array
    {
        $rules = array_merge(DocumentValidator::allRules());
        $allowIntro = ($_ENV['CMS_GRAPHQL_ALLOW_INTROSPECTION'] ?? getenv('CMS_GRAPHQL_ALLOW_INTROSPECTION')) === '1';
        $rules[DisableIntrospection::class] = new DisableIntrospection(
            $allowIntro ? DisableIntrospection::DISABLED : DisableIntrospection::ENABLED
        );
        $rules[QueryDepth::class] = new QueryDepth(14);
        $rules[QueryComplexity::class] = new QueryComplexity(220);

        return $rules;
    }

    private static function schema(): Schema
    {
        if (self::$schema instanceof Schema) {
            return self::$schema;
        }

        $fieldSchemaType = new ObjectType([
            'name' => 'PublicApiFieldSchema',
            'fields' => [
                'id' => Type::nonNull(Type::int()),
                'key' => Type::nonNull(Type::string()),
                'label' => Type::nonNull(Type::string()),
                'type' => Type::nonNull(Type::string()),
                'required' => Type::nonNull(Type::boolean()),
            ],
        ]);

        $contentTypeSummaryType = new ObjectType([
            'name' => 'PublicApiContentTypeSummary',
            'fields' => [
                'id' => Type::nonNull(Type::int()),
                'slug' => Type::nonNull(Type::string()),
                'name' => Type::nonNull(Type::string()),
                'description' => Type::string(),
                'hasPublicRoute' => Type::nonNull(Type::boolean()),
                'supportsSeo' => Type::nonNull(Type::boolean()),
                'supportsFeaturedImage' => Type::nonNull(Type::boolean()),
            ],
        ]);

        $contentTypeDetailType = new ObjectType([
            'name' => 'PublicApiContentTypeDetail',
            'fields' => [
                'id' => Type::nonNull(Type::int()),
                'slug' => Type::nonNull(Type::string()),
                'name' => Type::nonNull(Type::string()),
                'description' => Type::string(),
                'hasPublicRoute' => Type::nonNull(Type::boolean()),
                'supportsSeo' => Type::nonNull(Type::boolean()),
                'supportsFeaturedImage' => Type::nonNull(Type::boolean()),
                'fields' => Type::nonNull(Type::listOf(Type::nonNull($fieldSchemaType))),
            ],
        ]);

        $entrySummaryType = new ObjectType([
            'name' => 'PublicApiEntrySummary',
            'fields' => [
                'id' => Type::nonNull(Type::int()),
                'title' => Type::nonNull(Type::string()),
                'slug' => Type::nonNull(Type::string()),
                'status' => Type::nonNull(Type::string()),
                'publishedAt' => Type::string(),
                'updatedAt' => Type::nonNull(Type::string()),
                'publicPath' => Type::string(),
                'publicUrl' => Type::string(),
            ],
        ]);

        $listMetaType = new ObjectType([
            'name' => 'PublicApiEntryListMeta',
            'fields' => [
                'page' => Type::nonNull(Type::int()),
                'perPage' => Type::nonNull(Type::int()),
                'total' => Type::nonNull(Type::int()),
                'totalPages' => Type::nonNull(Type::int()),
            ],
        ]);

        $entryListType = new ObjectType([
            'name' => 'PublicApiEntryList',
            'fields' => [
                'meta' => Type::nonNull($listMetaType),
                'items' => Type::nonNull(Type::listOf(Type::nonNull($entrySummaryType))),
            ],
        ]);

        $fieldRowType = new ObjectType([
            'name' => 'PublicApiEntryFieldRow',
            'fields' => [
                'key' => Type::nonNull(Type::string()),
                'label' => Type::nonNull(Type::string()),
                'type' => Type::nonNull(Type::string()),
                'value' => Type::string(),
                'html' => Type::string(),
            ],
        ]);

        $taxonomyTermType = new ObjectType([
            'name' => 'PublicApiTaxonomyTerm',
            'fields' => [
                'id' => Type::nonNull(Type::int()),
                'slug' => Type::nonNull(Type::string()),
                'name' => Type::nonNull(Type::string()),
            ],
        ]);

        $taxonomyGroupType = new ObjectType([
            'name' => 'PublicApiTaxonomyGroup',
            'fields' => [
                'slug' => Type::nonNull(Type::string()),
                'name' => Type::nonNull(Type::string()),
                'terms' => Type::nonNull(Type::listOf(Type::nonNull($taxonomyTermType))),
            ],
        ]);

        $entryCoreType = new ObjectType([
            'name' => 'PublicApiEntryCore',
            'fields' => [
                'id' => Type::nonNull(Type::int()),
                'title' => Type::nonNull(Type::string()),
                'slug' => Type::nonNull(Type::string()),
                'status' => Type::nonNull(Type::string()),
                'publishedAt' => Type::string(),
                'createdAt' => Type::nonNull(Type::string()),
                'updatedAt' => Type::nonNull(Type::string()),
                'seoTitle' => Type::string(),
                'seoDescription' => Type::string(),
                'canonicalUrl' => Type::string(),
                'seoNoindex' => Type::nonNull(Type::boolean()),
                'featuredImageUrl' => Type::string(),
                'publicPath' => Type::string(),
                'publicUrl' => Type::string(),
            ],
        ]);

        $entryDetailType = new ObjectType([
            'name' => 'PublicApiEntryDetail',
            'fields' => [
                'entry' => Type::nonNull($entryCoreType),
                'fields' => Type::nonNull(Type::listOf(Type::nonNull($fieldRowType))),
                'taxonomies' => Type::nonNull(Type::listOf(Type::nonNull($taxonomyGroupType))),
            ],
        ]);

        $pageSummaryType = new ObjectType([
            'name' => 'PublicApiPageSummary',
            'fields' => [
                'id' => Type::nonNull(Type::int()),
                'title' => Type::nonNull(Type::string()),
                'slug' => Type::nonNull(Type::string()),
                'updatedAt' => Type::nonNull(Type::string()),
                'publicPath' => Type::nonNull(Type::string()),
                'publicUrl' => Type::nonNull(Type::string()),
            ],
        ]);

        $pageDetailType = new ObjectType([
            'name' => 'PublicApiPageDetail',
            'fields' => [
                'id' => Type::nonNull(Type::int()),
                'title' => Type::nonNull(Type::string()),
                'slug' => Type::nonNull(Type::string()),
                'content' => Type::string(),
                'sectionsHtml' => Type::string(),
                'tags' => Type::string(),
                'seoTitle' => Type::string(),
                'seoDescription' => Type::string(),
                'canonicalUrl' => Type::string(),
                'seoNoindex' => Type::nonNull(Type::boolean()),
                'ogTitle' => Type::string(),
                'ogDescription' => Type::string(),
                'twitterTitle' => Type::string(),
                'twitterDescription' => Type::string(),
                'schemaJson' => Type::string(),
                'featuredImageUrl' => Type::string(),
                'createdAt' => Type::nonNull(Type::string()),
                'updatedAt' => Type::nonNull(Type::string()),
                'publicPath' => Type::nonNull(Type::string()),
                'publicUrl' => Type::string(),
            ],
        ]);

        $queryType = new ObjectType([
            'name' => 'Query',
            'fields' => [
                'contentTypes' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($contentTypeSummaryType))),
                    'resolve' => static function ($root, array $args, PublicApiGraphQLContext $ctx): array {
                        $out = [];
                        foreach ($ctx->types->allOrdered() as $t) {
                            $s = PublicContentApi::typeSummary($t);
                            $out[] = [
                                'id' => $s['id'],
                                'slug' => $s['slug'],
                                'name' => $s['name'],
                                'description' => $s['description'],
                                'hasPublicRoute' => $s['has_public_route'],
                                'supportsSeo' => $s['supports_seo'],
                                'supportsFeaturedImage' => $s['supports_featured_image'],
                            ];
                        }

                        return $out;
                    },
                ],
                'contentType' => [
                    'type' => $contentTypeDetailType,
                    'args' => ['slug' => Type::nonNull(Type::string())],
                    'resolve' => static function ($root, array $args, PublicApiGraphQLContext $ctx): ?array {
                        $slug = (string) $args['slug'];
                        if (ReservedContentSlugs::isReserved($slug)) {
                            return null;
                        }
                        $t = $ctx->types->findBySlug($slug);
                        if ($t === null) {
                            return null;
                        }
                        $fieldList = $ctx->fields->forTypeOrdered($t->id);
                        $d = PublicContentApi::typeDetail($t, $fieldList);
                        $fields = [];
                        foreach ($d['fields'] as $f) {
                            $fields[] = [
                                'id' => $f['id'],
                                'key' => $f['key'],
                                'label' => $f['label'],
                                'type' => $f['type'],
                                'required' => $f['required'],
                            ];
                        }

                        return [
                            'id' => $d['id'],
                            'slug' => $d['slug'],
                            'name' => $d['name'],
                            'description' => $d['description'],
                            'hasPublicRoute' => $d['has_public_route'],
                            'supportsSeo' => $d['supports_seo'],
                            'supportsFeaturedImage' => $d['supports_featured_image'],
                            'fields' => $fields,
                        ];
                    },
                ],
                'entries' => [
                    'type' => Type::nonNull($entryListType),
                    'args' => [
                        'typeSlug' => Type::nonNull(Type::string()),
                        'page' => ['type' => Type::int(), 'defaultValue' => 1],
                        'perPage' => ['type' => Type::int(), 'defaultValue' => 20],
                        'status' => ['type' => Type::string(), 'defaultValue' => 'published'],
                    ],
                    'resolve' => static function ($root, array $args, PublicApiGraphQLContext $ctx): array {
                        $slug = (string) $args['typeSlug'];
                        if (ReservedContentSlugs::isReserved($slug)) {
                            return self::emptyEntryList((int) $args['page'], (int) $args['perPage']);
                        }
                        $t = $ctx->types->findBySlug($slug);
                        if ($t === null || !PublicApiContentRules::typeAllowedForRead($t, $ctx->auth)) {
                            return self::emptyEntryList((int) $args['page'], (int) $args['perPage']);
                        }
                        $st = PublicApiContentRules::statusesForEntryList((string) $args['status'], $ctx->auth);
                        if (!$st['ok']) {
                            throw new UserError(
                                $st['error'] === 'insufficient_scope'
                                    ? 'This status filter requires the read_drafts scope.'
                                    : 'Invalid status. Use published, draft, in_review, approved, archived, or all.'
                            );
                        }
                        $page = max(1, (int) $args['page']);
                        $perPage = max(1, min(50, (int) $args['perPage']));
                        $total = $ctx->entries->countForContentTypeWithStatuses($t->id, $st['statuses']);
                        $totalPages = max(1, (int) ceil(max(1, $total) / $perPage));
                        if ($page > $totalPages) {
                            $page = $totalPages;
                        }
                        $rows = $ctx->entries->listForContentTypePagedWithStatuses($t->id, $st['statuses'], $page, $perPage);
                        $base = $ctx->siteUrl;
                        $items = [];
                        foreach ($rows as $row) {
                            $sum = PublicContentApi::entrySummary($t, $row, $base);
                            $items[] = [
                                'id' => $sum['id'],
                                'title' => $sum['title'],
                                'slug' => $sum['slug'],
                                'status' => $sum['status'],
                                'publishedAt' => $sum['published_at'],
                                'updatedAt' => $sum['updated_at'],
                                'publicPath' => $sum['public_path'],
                                'publicUrl' => $sum['public_url'],
                            ];
                        }

                        return [
                            'meta' => [
                                'page' => $page,
                                'perPage' => $perPage,
                                'total' => $total,
                                'totalPages' => $totalPages,
                            ],
                            'items' => $items,
                        ];
                    },
                ],
                'entry' => [
                    'type' => $entryDetailType,
                    'args' => [
                        'typeSlug' => Type::nonNull(Type::string()),
                        'entrySlug' => Type::nonNull(Type::string()),
                    ],
                    'resolve' => static function ($root, array $args, PublicApiGraphQLContext $ctx): ?array {
                        $typeSlug = (string) $args['typeSlug'];
                        $entrySlug = (string) $args['entrySlug'];
                        if (ReservedContentSlugs::isReserved($typeSlug)) {
                            return null;
                        }
                        $t = $ctx->types->findBySlug($typeSlug);
                        if ($t === null || !PublicApiContentRules::typeAllowedForRead($t, $ctx->auth)) {
                            return null;
                        }
                        $entry = $ctx->auth->can('read_drafts')
                            ? $ctx->entries->findByTypeAndSlug($t->id, $entrySlug)
                            : $ctx->entries->findPublishedByTypeSlug($t->id, $entrySlug);
                        if ($entry === null) {
                            return null;
                        }
                        $fieldList = $ctx->fields->forTypeOrdered($t->id);
                        $valueMap = $ctx->values->valuesByFieldIdForEntry($entry->id);
                        $fieldRows = ContentEntryViewPresenter::buildFieldRows(
                            $fieldList,
                            $valueMap,
                            $ctx->mediaUrls,
                            $ctx->pdo,
                            rtrim($ctx->siteUrl, '/')
                        );
                        $featured = PublicContentApi::featuredImageUrlForEntry($entry, $fieldList, $valueMap, $ctx->mediaUrls);
                        $groups = $ctx->entryTaxonomies->termsGroupedForEntry($entry->id);
                        $data = PublicContentApi::entryDetail(
                            $t,
                            $entry,
                            $fieldRows,
                            $groups,
                            $featured !== '' ? $featured : null,
                            $ctx->siteUrl
                        );

                        return self::mapEntryDetail($data);
                    },
                ],
                'pages' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($pageSummaryType))),
                    'resolve' => static function ($root, array $args, PublicApiGraphQLContext $ctx): array {
                        $base = $ctx->siteUrl;
                        $items = [];
                        foreach ($ctx->pages->publishedForSitemap() as $row) {
                            $s = PublicContentApi::pageSummary($row, $base);
                            $items[] = [
                                'id' => $s['id'],
                                'title' => $s['title'],
                                'slug' => $s['slug'],
                                'updatedAt' => $s['updated_at'],
                                'publicPath' => $s['public_path'],
                                'publicUrl' => $s['public_url'],
                            ];
                        }

                        return $items;
                    },
                ],
                'page' => [
                    'type' => $pageDetailType,
                    'args' => ['slug' => Type::nonNull(Type::string())],
                    'resolve' => static function ($root, array $args, PublicApiGraphQLContext $ctx): ?array {
                        $slug = (string) $args['slug'];
                        $page = $ctx->pages->findPublishedBySlug($slug);
                        if ($page === null) {
                            return null;
                        }
                        $rows = $ctx->pageSections->listForPage($page->id);
                        $sectionsHtml = $rows !== [] ? $ctx->sectionRenderer->renderPage($ctx->twig, $rows) : '';
                        $featuredUrl = '';
                        if ($page->featuredImageId !== null) {
                            $featuredUrl = $ctx->mediaUrls->pathForId($page->featuredImageId);
                        }
                        $d = PublicContentApi::pageDetail($page, $featuredUrl !== '' ? $featuredUrl : null, $sectionsHtml, $ctx->siteUrl);
                        $p = $d['page'];

                        return [
                            'id' => $p['id'],
                            'title' => $p['title'],
                            'slug' => $p['slug'],
                            'content' => $p['content'],
                            'sectionsHtml' => $p['sections_html'],
                            'tags' => $p['tags'],
                            'seoTitle' => $p['seo_title'],
                            'seoDescription' => $p['seo_description'],
                            'canonicalUrl' => $p['canonical_url'],
                            'seoNoindex' => $p['seo_noindex'],
                            'ogTitle' => $p['og_title'],
                            'ogDescription' => $p['og_description'],
                            'twitterTitle' => $p['twitter_title'],
                            'twitterDescription' => $p['twitter_description'],
                            'schemaJson' => $p['schema_json'],
                            'featuredImageUrl' => $p['featured_image_url'],
                            'createdAt' => $p['created_at'],
                            'updatedAt' => $p['updated_at'],
                            'publicPath' => $p['public_path'],
                            'publicUrl' => $p['public_url'],
                        ];
                    },
                ],
            ],
        ]);

        self::$schema = new Schema(['query' => $queryType]);

        return self::$schema;
    }

    /**
     * @return array<string, mixed>
     */
    private static function emptyEntryList(int $page, int $perPage): array
    {
        $perPage = max(1, min(50, $perPage));

        return [
            'meta' => [
                'page' => max(1, $page),
                'perPage' => $perPage,
                'total' => 0,
                'totalPages' => 1,
            ],
            'items' => [],
        ];
    }

    /**
     * @param array<string, mixed> $data from PublicContentApi::entryDetail
     * @return array<string, mixed>
     */
    private static function mapEntryDetail(array $data): array
    {
        $e = $data['entry'];
        $fields = [];
        foreach ($data['fields'] as $f) {
            $v = $f['value'];
            if ($v !== null && !is_string($v)) {
                $v = is_scalar($v) ? (string) $v : json_encode($v, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            }
            $fields[] = [
                'key' => $f['key'],
                'label' => $f['label'],
                'type' => $f['type'],
                'value' => $v,
                'html' => $f['html'],
            ];
        }
        $tax = [];
        foreach ($data['taxonomies'] as $g) {
            $terms = [];
            foreach ($g['terms'] as $term) {
                $terms[] = [
                    'id' => $term['id'],
                    'slug' => $term['slug'],
                    'name' => $term['name'],
                ];
            }
            $tax[] = [
                'slug' => $g['slug'],
                'name' => $g['name'],
                'terms' => $terms,
            ];
        }

        return [
            'entry' => [
                'id' => $e['id'],
                'title' => $e['title'],
                'slug' => $e['slug'],
                'status' => $e['status'],
                'publishedAt' => $e['published_at'],
                'createdAt' => $e['created_at'],
                'updatedAt' => $e['updated_at'],
                'seoTitle' => $e['seo_title'],
                'seoDescription' => $e['seo_description'],
                'canonicalUrl' => $e['canonical_url'],
                'seoNoindex' => $e['seo_noindex'],
                'featuredImageUrl' => $e['featured_image_url'],
                'publicPath' => $e['public_path'],
                'publicUrl' => $e['public_url'],
            ],
            'fields' => $fields,
            'taxonomies' => $tax,
        ];
    }
}
