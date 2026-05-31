<?php

declare(strict_types=1);

namespace App\Revisions;

use App\Settings;

/**
 * Caps how many revisions are kept per page or content entry (0 = unlimited).
 */
final class RevisionRetentionSettings
{
    public const DEFAULT_PAGE_MAX = 50;

    public const DEFAULT_ENTRY_MAX = 50;

    public const MAX_LIMIT = 500;

    public static function pageMax(): int
    {
        return self::normalize(Settings::get('revision_retention_page_max', (string) self::DEFAULT_PAGE_MAX));
    }

    public static function entryMax(): int
    {
        return self::normalize(Settings::get('revision_retention_entry_max', (string) self::DEFAULT_ENTRY_MAX));
    }

    public static function normalize(mixed $raw): int
    {
        if ($raw === null || $raw === '') {
            return self::DEFAULT_PAGE_MAX;
        }
        $n = (int) $raw;

        return max(0, min(self::MAX_LIMIT, $n));
    }
}
