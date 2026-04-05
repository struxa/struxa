<?php

declare(strict_types=1);

namespace App\Page;

/**
 * Comma-separated tag input ↔ JSON slug list for storage.
 */
final class PageTagParser
{
    public const MAX_TAGS = 24;

    public const MAX_INPUT_LEN = 2000;

    public const MAX_SLUG_LEN = 48;

    /**
     * @return list<string> unique URL-safe slugs
     */
    public static function parseCommaSeparated(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        $parts = preg_split('/\s*,\s*/', $raw, -1, PREG_SPLIT_NO_EMPTY);
        if ($parts === false) {
            return [];
        }

        $out = [];
        foreach ($parts as $p) {
            $label = trim((string) $p);
            if ($label === '') {
                continue;
            }
            $slug = PageSlugger::slugify($label);
            if (strlen($slug) > self::MAX_SLUG_LEN) {
                $slug = substr($slug, 0, self::MAX_SLUG_LEN);
                $slug = rtrim($slug, '-');
            }
            if ($slug === '' || $slug === 'page') {
                $slug = PageSlugger::slugify($label . '-tag');
            }
            if ($slug !== '' && !in_array($slug, $out, true)) {
                $out[] = $slug;
            }
            if (count($out) >= self::MAX_TAGS) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param list<string> $slugs
     */
    public static function toJson(array $slugs): ?string
    {
        if ($slugs === []) {
            return null;
        }

        return json_encode(array_values($slugs), JSON_THROW_ON_ERROR);
    }

    /**
     * @return list<string>
     */
    public static function fromJson(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        try {
            $d = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        if (!is_array($d)) {
            return [];
        }
        $out = [];
        foreach ($d as $v) {
            if (is_string($v) && $v !== '' && !in_array($v, $out, true)) {
                $out[] = $v;
            }
        }

        return $out;
    }

    /**
     * Friendly edit string from stored slugs (not a perfect round-trip for odd slugs).
     *
     * @param list<string> $slugs
     */
    public static function slugsToEditString(array $slugs): string
    {
        if ($slugs === []) {
            return '';
        }

        return implode(', ', array_map(static function (string $s): string {
            $t = str_replace('-', ' ', $s);

            return $t !== '' ? ucwords($t) : $s;
        }, $slugs));
    }
}
