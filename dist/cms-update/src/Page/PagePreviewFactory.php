<?php

declare(strict_types=1);

namespace App\Page;

use App\Access\WorkflowService;
use App\Seo\SeoFormParser;

/**
 * Builds an in-memory {@see Page} from unsaved editor POST fields (never persisted).
 */
final class PagePreviewFactory
{
    private const SLUG_OK = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    /**
     * @param array<string, mixed> $body Parsed POST body
     */
    public static function fromPostBody(array $body, ?Page $existing): Page
    {
        $title = isset($body['title']) ? trim((string) $body['title']) : '';
        if ($title === '') {
            $title = 'Preview';
        }

        $slugRaw = isset($body['slug']) ? trim((string) $body['slug']) : '';
        if ($slugRaw === '') {
            $slug = $existing?->slug ?? 'preview';
        } elseif (preg_match(self::SLUG_OK, $slugRaw) === 1) {
            $slug = $slugRaw;
        } else {
            $slug = PageSlugger::slugify($slugRaw);
            if ($slug === '') {
                $slug = $existing?->slug ?? 'preview';
            }
        }

        $contentRaw = isset($body['content']) ? (string) $body['content'] : '';
        $content = PageContentSanitizer::fromEnv()->sanitize($contentRaw);

        $seoTitle = isset($body['seo_title']) ? trim(strip_tags((string) $body['seo_title'])) : '';
        $seoDesc = isset($body['seo_description']) ? trim(strip_tags((string) $body['seo_description'])) : '';
        $tagsRaw = isset($body['tags']) ? trim((string) $body['tags']) : '';
        $tags = PageTagParser::parseCommaSeparated($tagsRaw);

        $status = isset($body['status']) ? trim((string) $body['status']) : 'draft';
        if (!in_array($status, WorkflowService::STATUSES, true)) {
            $status = 'draft';
        }

        $featuredImageId = $existing?->featuredImageId;
        if (array_key_exists('featured_image_id', $body)) {
            $fr = trim((string) $body['featured_image_id']);
            $featuredImageId = $fr === '' ? null : (ctype_digit($fr) ? (int) $fr : $featuredImageId);
        }

        $canonicalUrl = self::nullableStr($body, 'canonical_url', 2048);
        $seoNoindex = !empty($body['seo_noindex']);
        $ogTitle = self::nullableStr($body, 'og_title', 255);
        $ogDescription = self::nullableStr($body, 'og_description', 500);
        $twitterTitle = self::nullableStr($body, 'twitter_title', 255);
        $twitterDescription = self::nullableStr($body, 'twitter_description', 500);
        $ogImageId = self::optionalPositiveInt($body, 'og_image_id', $existing?->ogImageId);
        $twitterImageId = self::optionalPositiveInt($body, 'twitter_image_id', $existing?->twitterImageId);

        $schemaJson = null;
        $schemaRaw = isset($body['schema_json']) ? trim((string) $body['schema_json']) : '';
        if ($schemaRaw !== '') {
            $norm = SeoFormParser::normalizeSchemaJsonForStorage($schemaRaw);
            $schemaJson = $norm['value'];
        } elseif ($existing !== null) {
            $schemaJson = $existing->schemaJson;
        }

        $id = $existing?->id ?? 0;
        $now = gmdate('c');

        return new Page(
            $id,
            $title,
            $slug,
            $seoTitle !== '' ? $seoTitle : null,
            $seoDesc !== '' ? $seoDesc : null,
            $tags,
            $featuredImageId,
            $canonicalUrl,
            $seoNoindex,
            $ogTitle,
            $ogDescription,
            $ogImageId,
            $twitterTitle,
            $twitterDescription,
            $twitterImageId,
            $schemaJson,
            $content,
            $status,
            $existing?->publishedAt,
            $existing?->scheduledPublishAt,
            $existing?->scheduledUnpublishAt,
            $now,
            $now,
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function nullableStr(array $body, string $key, int $max): ?string
    {
        $s = isset($body[$key]) ? trim(str_replace("\0", '', (string) $body[$key])) : '';
        if ($s === '') {
            return null;
        }
        if (mb_strlen($s) > $max) {
            return mb_substr($s, 0, $max);
        }

        return $s;
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function optionalPositiveInt(array $body, string $key, ?int $fallback): ?int
    {
        if (!array_key_exists($key, $body)) {
            return $fallback;
        }
        $r = trim((string) $body[$key]);
        if ($r === '') {
            return null;
        }
        if (!ctype_digit($r)) {
            return $fallback;
        }
        $n = (int) $r;

        return $n > 0 ? $n : null;
    }
}
