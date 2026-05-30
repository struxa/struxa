<?php

declare(strict_types=1);

namespace App\Seo;

use App\Media\MediaRepository;

/**
 * Parses extended SEO fields from POST bodies (pages, entries, taxonomy terms).
 */
final class SeoFormParser
{
    /**
     * @param array<string, mixed> $body
     * @return array{
     *   errors: array<string, string>,
     *   canonical_url: ?string,
     *   seo_noindex: bool,
     *   og_title: ?string,
     *   og_description: ?string,
     *   og_image_id: ?int,
     *   twitter_title: ?string,
     *   twitter_description: ?string,
     *   twitter_image_id: ?int,
     *   schema_json: ?string
     * }
     */
    /**
     * Validates optional JSON-LD for storage (pages, entries, taxonomy terms, blueprints).
     *
     * @return array{value: ?string, error: ?string} error set when input is non-empty but invalid
     */
    public static function normalizeSchemaJsonForStorage(string $raw): array
    {
        $raw = trim(str_replace("\0", '', $raw));
        if ($raw === '') {
            return ['value' => null, 'error' => null];
        }
        if (strlen($raw) > 100000) {
            return ['value' => null, 'error' => 'JSON-LD is too large.'];
        }
        json_decode($raw, true, 512);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['value' => null, 'error' => 'Enter valid JSON for schema.org structured data.'];
        }

        return ['value' => $raw, 'error' => null];
    }

    public static function parse(array $body, MediaRepository $media, string $prefix = ''): array
    {
        $errors = [];
        $p = $prefix !== '' ? $prefix . '_' : '';

        $canonical = self::str($body, $p . 'canonical_url');
        if ($canonical !== '' && mb_strlen($canonical) > 2048) {
            $errors[$p . 'canonical_url'] = 'Canonical URL is too long.';
        }
        $canonical = $canonical !== '' ? $canonical : null;

        $noindex = !empty($body[$p . 'seo_noindex']);

        $ogTitle = self::nullableStr($body, $p . 'og_title', 255, $p . 'og_title', 'Open Graph title', $errors);
        $ogDesc = self::nullableStr($body, $p . 'og_description', 500, $p . 'og_description', 'Open Graph description', $errors);
        $twTitle = self::nullableStr($body, $p . 'twitter_title', 255, $p . 'twitter_title', 'Twitter title', $errors);
        $twDesc = self::nullableStr($body, $p . 'twitter_description', 500, $p . 'twitter_description', 'Twitter description', $errors);

        $ogImg = self::optionalImageId($body, $p . 'og_image_id', 'OG image', $errors, $media);
        $twImg = self::optionalImageId($body, $p . 'twitter_image_id', 'Twitter image', $errors, $media);

        $schemaRaw = isset($body[$p . 'schema_json']) ? trim((string) $body[$p . 'schema_json']) : '';
        $norm = self::normalizeSchemaJsonForStorage($schemaRaw);
        if ($norm['error'] !== null) {
            $errors[$p . 'schema_json'] = $norm['error'];
        }
        $schemaJson = $norm['value'];

        $focusKeyphrase = self::str($body, $p . 'focus_keyphrase');
        if ($focusKeyphrase !== '' && mb_strlen($focusKeyphrase) > 120) {
            $errors[$p . 'focus_keyphrase'] = 'Focus keyphrase must be 120 characters or fewer.';
            $focusKeyphrase = '';
        }
        $focusKeyphrase = $focusKeyphrase !== '' ? $focusKeyphrase : null;

        return [
            'errors' => $errors,
            'canonical_url' => $canonical,
            'seo_noindex' => $noindex,
            'og_title' => $ogTitle,
            'og_description' => $ogDesc,
            'og_image_id' => $ogImg,
            'twitter_title' => $twTitle,
            'twitter_description' => $twDesc,
            'twitter_image_id' => $twImg,
            'schema_json' => $schemaJson,
            'focus_keyphrase' => $focusKeyphrase,
        ];
    }

    /**
     * Extended SEO for taxonomy terms (includes optional SEO title + description).
     *
     * @param array<string, mixed> $body
     * @return array{
     *   errors: array<string, string>,
     *   seo_title: ?string,
     *   seo_description: ?string,
     *   canonical_url: ?string,
     *   seo_noindex: bool,
     *   og_title: ?string,
     *   og_description: ?string,
     *   og_image_id: ?int,
     *   twitter_title: ?string,
     *   twitter_description: ?string,
     *   twitter_image_id: ?int,
     *   schema_json: ?string
     * }
     */
    public static function parseTerm(array $body, MediaRepository $media): array
    {
        $base = self::parse($body, $media, '');
        $errors = $base['errors'];

        $seoTitle = self::str($body, 'seo_title');
        if ($seoTitle !== '' && mb_strlen($seoTitle) > 255) {
            $errors['seo_title'] = 'SEO title must be 255 characters or fewer.';
            $seoTitle = '';
        }
        $seoTitle = $seoTitle !== '' ? $seoTitle : null;

        $seoDesc = self::str($body, 'seo_description');
        if ($seoDesc !== '' && mb_strlen($seoDesc) > 500) {
            $errors['seo_description'] = 'Meta description must be 500 characters or fewer.';
            $seoDesc = '';
        }
        $seoDesc = $seoDesc !== '' ? $seoDesc : null;

        return array_merge($base, [
            'errors' => $errors,
            'seo_title' => $seoTitle,
            'seo_description' => $seoDesc,
        ]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function str(array $body, string $key): string
    {
        $v = $body[$key] ?? '';

        return trim(is_string($v) ? str_replace("\0", '', $v) : '');
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $errors
     */
    private static function nullableStr(array $body, string $key, int $max, string $errKey, string $label, array &$errors): ?string
    {
        $s = self::str($body, $key);
        if ($s === '') {
            return null;
        }
        if (mb_strlen($s) > $max) {
            $errors[$errKey] = "{$label} must be {$max} characters or fewer.";

            return null;
        }

        return $s;
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $errors
     */
    private static function optionalImageId(array $body, string $key, string $label, array &$errors, MediaRepository $media): ?int
    {
        $raw = self::str($body, $key);
        if ($raw === '') {
            return null;
        }
        if (!ctype_digit($raw)) {
            $errors[$key] = "{$label}: invalid media ID.";

            return null;
        }
        $id = (int) $raw;
        $m = $media->findById($id);
        if ($m === null || !$m->isImage()) {
            $errors[$key] = "{$label}: choose an image from the media library.";

            return null;
        }

        return $id;
    }
}
