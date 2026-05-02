<?php

declare(strict_types=1);

namespace App\Manifest;

/**
 * Shared parsing for plugin.json / theme.json marketplace-style fields (tags, http URLs).
 */
final class ManifestMeta
{
    private const MAX_TAG_LEN = 48;

    private const MAX_TAGS = 24;

    /**
     * @return list<string>
     */
    public static function parseTags(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $t) {
            if (!is_string($t)) {
                continue;
            }
            $t = trim($t);
            if ($t === '' || strlen($t) > self::MAX_TAG_LEN) {
                continue;
            }
            $out[] = $t;
            if (count($out) >= self::MAX_TAGS) {
                break;
            }
        }

        return $out;
    }

    /**
     * Accepts a bounded, trimmed string (caller enforces max length). Returns null if not http(s).
     */
    public static function httpUrlOrNull(?string $trimmed): ?string
    {
        if ($trimmed === null || $trimmed === '') {
            return null;
        }
        if (!preg_match('#^https?://#i', $trimmed)) {
            return null;
        }

        return $trimmed;
    }
}
