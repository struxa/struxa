<?php

declare(strict_types=1);

namespace App\Api;

use App\Content\ContentEntry;
use App\Content\ContentField;
use App\Taxonomy\Taxonomy;

/**
 * Maps JSON API bodies to the admin-style form array expected by ContentEntryFormValidator.
 */
final class PublicApiEntryPayload
{
    /**
     * @param list<ContentField> $fieldList
     * @param array<int, string|null> $valueMap
     * @param array<int, list<int>> $taxonomySelection taxonomy_id => term ids
     * @param list<Taxonomy> $taxonomies
     * @param array<string, mixed> $json
     * @return array<string, mixed>
     */
    public static function toFormBody(
        array $json,
        bool $isCreate,
        ?ContentEntry $entry,
        array $fieldList,
        array $valueMap,
        array $taxonomySelection,
        array $taxonomies,
    ): array {
        $title = $isCreate
            ? (isset($json['title']) ? trim((string) $json['title']) : '')
            : (array_key_exists('title', $json) ? trim((string) $json['title']) : $entry->title);

        $slug = $isCreate
            ? (isset($json['slug']) ? trim((string) $json['slug']) : '')
            : (array_key_exists('slug', $json) ? trim((string) $json['slug']) : $entry->slug);

        $status = $isCreate
            ? (isset($json['status']) ? trim((string) $json['status']) : 'draft')
            : (array_key_exists('status', $json) ? trim((string) $json['status']) : $entry->status);

        $body = [
            'title' => $title,
            'slug' => $slug,
            'status' => $status,
            'custom_fields' => self::buildCustomFields($json, $isCreate, $fieldList, $valueMap),
        ];

        if ($isCreate) {
            $body['featured_image_id'] = self::featuredImageIdString($json, null);
            $body['seo_title'] = isset($json['seo_title']) ? trim((string) $json['seo_title']) : '';
            $body['seo_description'] = isset($json['seo_description']) ? trim((string) $json['seo_description']) : '';
            $body['published_at'] = isset($json['published_at']) ? trim((string) $json['published_at']) : '';
            $body['scheduled_publish_at'] = isset($json['scheduled_publish_at']) ? trim((string) $json['scheduled_publish_at']) : '';
            $body['scheduled_unpublish_at'] = isset($json['scheduled_unpublish_at']) ? trim((string) $json['scheduled_unpublish_at']) : '';
        } else {
            $body['featured_image_id'] = array_key_exists('featured_image_id', $json)
                ? self::featuredImageIdString($json, $entry->featuredImageId)
                : ($entry->featuredImageId !== null ? (string) $entry->featuredImageId : '');
            $body['seo_title'] = array_key_exists('seo_title', $json)
                ? trim((string) $json['seo_title'])
                : (string) ($entry->seoTitle ?? '');
            $body['seo_description'] = array_key_exists('seo_description', $json)
                ? trim((string) $json['seo_description'])
                : (string) ($entry->seoDescription ?? '');
            $body['published_at'] = array_key_exists('published_at', $json)
                ? trim((string) $json['published_at'])
                : (string) ($entry->publishedAt ?? '');
            $body['scheduled_publish_at'] = array_key_exists('scheduled_publish_at', $json)
                ? trim((string) $json['scheduled_publish_at'])
                : (string) ($entry->scheduledPublishAt ?? '');
            $body['scheduled_unpublish_at'] = array_key_exists('scheduled_unpublish_at', $json)
                ? trim((string) $json['scheduled_unpublish_at'])
                : (string) ($entry->scheduledUnpublishAt ?? '');
        }

        $taxTerms = self::mergeTaxonomyInput($json, $taxonomySelection, $taxonomies);
        $body['taxonomy_terms'] = $taxTerms;
        $body['taxonomy_terms_submitted'] = '1';

        foreach ([
            'canonical_url', 'seo_noindex', 'og_title', 'og_description', 'og_image_id',
            'twitter_title', 'twitter_description', 'twitter_image_id', 'schema_json',
        ] as $seoKey) {
            if (array_key_exists($seoKey, $json)) {
                $body[$seoKey] = $json[$seoKey];
            }
        }

        return $body;
    }

    /**
     * Fills omitted schedule keys from the stored entry so PATCH does not clear them when omitted from JSON.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public static function mergeScheduleFromEntryIfMissing(array $body, ContentEntry $entry): array
    {
        if (!array_key_exists('scheduled_publish_at', $body)) {
            $body['scheduled_publish_at'] = (string) ($entry->scheduledPublishAt ?? '');
        }
        if (!array_key_exists('scheduled_unpublish_at', $body)) {
            $body['scheduled_unpublish_at'] = (string) ($entry->scheduledUnpublishAt ?? '');
        }

        return $body;
    }

    /**
     * Fills omitted SEO keys from the stored entry so SeoFormParser does not clear them on PATCH.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public static function mergeSeoFromEntryIfMissing(array $body, ContentEntry $entry): array
    {
        $map = [
            'canonical_url' => $entry->canonicalUrl ?? '',
            'seo_noindex' => $entry->seoNoindex ? '1' : '',
            'og_title' => $entry->ogTitle ?? '',
            'og_description' => $entry->ogDescription ?? '',
            'og_image_id' => $entry->ogImageId !== null ? (string) $entry->ogImageId : '',
            'twitter_title' => $entry->twitterTitle ?? '',
            'twitter_description' => $entry->twitterDescription ?? '',
            'twitter_image_id' => $entry->twitterImageId !== null ? (string) $entry->twitterImageId : '',
            'schema_json' => $entry->schemaJson ?? '',
        ];
        foreach ($map as $k => $default) {
            if (!array_key_exists($k, $body)) {
                $body[$k] = $default;
            }
        }

        return $body;
    }

    /**
     * @param array<int, list<int>> $existingByTaxonomy
     * @param list<Taxonomy> $taxonomies
     * @return array<int|string, list<int>|mixed>
     */
    private static function mergeTaxonomyInput(array $json, array $existingByTaxonomy, array $taxonomies): array
    {
        $raw = [];
        if (isset($json['taxonomies']) && is_array($json['taxonomies'])) {
            foreach ($json['taxonomies'] as $slug => $ids) {
                $slug = (string) $slug;
                foreach ($taxonomies as $tx) {
                    if ($tx->slug === $slug) {
                        $raw[$tx->id] = is_array($ids) ? array_map('intval', $ids) : [];
                    }
                }
            }
        }
        if (isset($json['taxonomy_terms']) && is_array($json['taxonomy_terms'])) {
            foreach ($json['taxonomy_terms'] as $k => $bucket) {
                $tid = is_numeric($k) ? (int) $k : 0;
                if ($tid < 1) {
                    continue;
                }
                if (!is_array($bucket)) {
                    $bucket = $bucket !== '' && $bucket !== null ? [$bucket] : [];
                }
                $raw[$tid] = [];
                foreach ($bucket as $idRaw) {
                    if ($idRaw === '' || $idRaw === null) {
                        continue;
                    }
                    if (is_numeric($idRaw)) {
                        $raw[$tid][] = (int) $idRaw;
                    }
                }
            }
        }
        if ($raw === []) {
            return $existingByTaxonomy;
        }

        return $raw;
    }

    /**
     * @param list<ContentField> $fieldList
     * @param array<int, string|null> $valueMap
     * @return array<int, string|null>
     */
    private static function buildCustomFields(array $json, bool $isCreate, array $fieldList, array $valueMap): array
    {
        $fieldsObj = $json['fields'] ?? null;
        $cf = [];
        foreach ($fieldList as $f) {
            if (is_array($fieldsObj) && array_key_exists($f->fieldKey, $fieldsObj)) {
                $cf[$f->id] = self::normalizeFieldInput($fieldsObj[$f->fieldKey]);
            } elseif (!$isCreate) {
                $cf[$f->id] = $valueMap[$f->id] ?? null;
            } else {
                $cf[$f->id] = null;
            }
        }

        return $cf;
    }

    private static function normalizeFieldInput(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        if (is_bool($v)) {
            return $v ? '1' : '0';
        }
        if (is_int($v) || is_float($v)) {
            return (string) $v;
        }
        if (is_string($v)) {
            return $v;
        }
        if (is_array($v)) {
            $enc = json_encode($v, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            return $enc;
        }

        return null;
    }

    private static function featuredImageIdString(array $json, ?int $existing): string
    {
        if (!array_key_exists('featured_image_id', $json)) {
            return $existing !== null ? (string) $existing : '';
        }
        $fi = $json['featured_image_id'];
        if ($fi === null || $fi === '') {
            return '';
        }

        return (string) (int) $fi;
    }
}
