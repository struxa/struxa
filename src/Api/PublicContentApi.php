<?php

declare(strict_types=1);

namespace App\Api;

use App\Content\ContentEntry;
use App\Content\ContentEntryRefsFieldOptions;
use App\Content\ContentField;
use App\Content\ContentType;
use App\Media\MediaUrlHelper;
use App\Page\Page;
use App\Taxonomy\Taxonomy;
use App\Taxonomy\TaxonomyTerm;

/**
 * Shapes for GET /api/v1 JSON responses (published content only).
 */
final class PublicContentApi
{
    /**
     * @return array<string, mixed>
     */
    public static function typeSummary(ContentType $t): array
    {
        return [
            'id' => $t->id,
            'slug' => $t->slug,
            'name' => $t->name,
            'description' => $t->description,
            'has_public_route' => $t->hasPublicRoute,
            'supports_seo' => $t->supportsSeo,
            'supports_featured_image' => $t->supportsFeaturedImage,
        ];
    }

    /**
     * @param list<ContentField> $fields
     * @return array<string, mixed>
     */
    public static function typeDetail(ContentType $t, array $fields): array
    {
        $fieldMaps = [];
        foreach ($fields as $f) {
            $fieldMaps[] = self::fieldSchema($f);
        }

        return self::typeSummary($t) + ['fields' => $fieldMaps];
    }

    /**
     * @return array<string, mixed>
     */
    public static function fieldSchema(ContentField $f): array
    {
        $out = [
            'id' => $f->id,
            'key' => $f->fieldKey,
            'label' => $f->label,
            'type' => $f->fieldType,
            'required' => $f->isRequired,
            'placeholder' => $f->placeholder,
            'help_text' => $f->helpText,
            'sort_order' => $f->sortOrder,
        ];
        if ($f->fieldType === 'select') {
            $out['options'] = $f->selectOptions();
        }
        if ($f->fieldType === 'entry_refs') {
            $o = ContentEntryRefsFieldOptions::fromField($f);
            $out['entry_ref_settings'] = [
                'target_content_type_id' => $o->targetContentTypeId,
                'max_refs' => $o->maxRefs,
                'require_public_targets' => $o->requirePublicTargets,
            ];
            $out['value_format'] = 'JSON array of numeric entry IDs, e.g. [12,34]';
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public static function entrySummary(ContentType $type, array $row, string $siteUrl): array
    {
        $slug = (string) ($row['slug'] ?? '');
        $status = (string) ($row['status'] ?? '');
        $path = $type->hasPublicRoute && $slug !== '' ? '/' . $type->slug . '/' . $slug : null;

        return [
            'id' => (int) ($row['id'] ?? 0),
            'title' => (string) ($row['title'] ?? ''),
            'slug' => $slug,
            'status' => $status,
            'published_at' => isset($row['published_at']) && $row['published_at'] !== null ? (string) $row['published_at'] : null,
            'updated_at' => (string) ($row['updated_at'] ?? ''),
            'public_url' => $path !== null && $siteUrl !== '' ? $siteUrl . $path : $path,
            'public_path' => $path,
        ];
    }

    /**
     * @param list<array<string, mixed>> $fieldRows from ContentEntryViewPresenter::buildFieldRows
     * @param list<array{taxonomy: Taxonomy, terms: list<TaxonomyTerm>}> $taxonomyGroups
     * @return array<string, mixed>
     */
    public static function entryDetail(
        ContentType $type,
        ContentEntry $entry,
        array $fieldRows,
        array $taxonomyGroups,
        ?string $featuredUrl,
        string $siteUrl,
    ): array {
        $path = $type->hasPublicRoute ? '/' . $type->slug . '/' . $entry->slug : null;
        $fields = [];
        foreach ($fieldRows as $row) {
            $fields[] = [
                'key' => $row['field_key'],
                'label' => $row['label'],
                'type' => $row['field_type'],
                'value' => $row['value_raw'],
                'html' => $row['html'],
            ];
        }
        $taxOut = [];
        foreach ($taxonomyGroups as $g) {
            $tx = $g['taxonomy'];
            $terms = [];
            foreach ($g['terms'] as $term) {
                $terms[] = [
                    'id' => $term->id,
                    'slug' => $term->slug,
                    'name' => $term->name,
                ];
            }
            $taxOut[] = [
                'slug' => $tx->slug,
                'name' => $tx->name,
                'terms' => $terms,
            ];
        }

        return [
            'entry' => [
                'id' => $entry->id,
                'title' => $entry->title,
                'slug' => $entry->slug,
                'status' => $entry->status,
                'published_at' => $entry->publishedAt,
                'created_at' => $entry->createdAt,
                'updated_at' => $entry->updatedAt,
                'seo_title' => $entry->seoTitle,
                'seo_description' => $entry->seoDescription,
                'canonical_url' => $entry->canonicalUrl,
                'seo_noindex' => $entry->seoNoindex,
                'featured_image_url' => self::absoluteUrl($siteUrl, $featuredUrl),
                'public_path' => $path,
                'public_url' => $path !== null && $siteUrl !== '' ? $siteUrl . $path : null,
            ],
            'fields' => $fields,
            'taxonomies' => $taxOut,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function pageSummary(array $row, string $siteUrl): array
    {
        $slug = (string) ($row['slug'] ?? '');

        return [
            'id' => (int) ($row['id'] ?? 0),
            'title' => (string) ($row['title'] ?? ''),
            'slug' => $slug,
            'updated_at' => (string) ($row['updated_at'] ?? ''),
            'public_url' => $siteUrl !== '' ? $siteUrl . '/p/' . $slug : '/p/' . $slug,
            'public_path' => '/p/' . $slug,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function pageDetail(Page $page, ?string $featuredUrl, string $sectionsHtml, string $siteUrl): array
    {
        $slug = $page->slug;

        return [
            'page' => [
                'id' => $page->id,
                'title' => $page->title,
                'slug' => $slug,
                'content' => $page->content,
                'sections_html' => $sectionsHtml,
                'tags' => $page->tags,
                'seo_title' => $page->seoTitle,
                'seo_description' => $page->seoDescription,
                'canonical_url' => $page->canonicalUrl,
                'seo_noindex' => $page->seoNoindex,
                'og_title' => $page->ogTitle,
                'og_description' => $page->ogDescription,
                'twitter_title' => $page->twitterTitle,
                'twitter_description' => $page->twitterDescription,
                'schema_json' => $page->schemaJson,
                'featured_image_url' => self::absoluteUrl($siteUrl, $featuredUrl),
                'created_at' => $page->createdAt,
                'updated_at' => $page->updatedAt,
                'public_path' => '/p/' . $slug,
                'public_url' => $siteUrl !== '' ? $siteUrl . '/p/' . $slug : null,
            ],
        ];
    }

    /**
     * @param list<ContentField> $fieldList
     * @param array<int, string|null> $valueMap
     */
    public static function featuredImageUrlForEntry(
        ContentEntry $entry,
        array $fieldList,
        array $valueMap,
        MediaUrlHelper $mediaUrls,
    ): string {
        if ($entry->featuredImageId !== null) {
            $u = $mediaUrls->pathForId($entry->featuredImageId);
            if ($u !== '') {
                return $u;
            }
        }
        foreach ($fieldList as $f) {
            if ($f->fieldKey !== 'thumbnail_url' && $f->fieldKey !== 'card_image_url') {
                continue;
            }
            $ext = trim((string) ($valueMap[$f->id] ?? ''));
            if ($ext === '') {
                continue;
            }
            if (preg_match('#^https?://#i', $ext) === 1) {
                return $ext;
            }
            if ($ext[0] === '/' || $ext[0] === '.') {
                return $ext;
            }
        }

        return '';
    }

    private static function absoluteUrl(string $siteUrl, ?string $pathOrUrl): ?string
    {
        if ($pathOrUrl === null || $pathOrUrl === '') {
            return null;
        }
        if (preg_match('#^https?://#i', $pathOrUrl) === 1) {
            return $pathOrUrl;
        }
        if ($siteUrl === '') {
            return $pathOrUrl;
        }

        return $siteUrl . (str_starts_with($pathOrUrl, '/') ? $pathOrUrl : '/' . $pathOrUrl);
    }
}
