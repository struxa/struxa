<?php

declare(strict_types=1);

namespace App\Content;

/**
 * Canonical storage for "entry_refs" custom fields: JSON array of positive entry IDs, e.g. [12,34].
 * Order is preserved (first occurrence wins for duplicates).
 */
final class ContentEntryReferenceIds
{
    /**
     * @return list<int>
     */
    public static function parse(?string $stored): array
    {
        if ($stored === null || trim($stored) === '') {
            return [];
        }
        $stored = trim($stored);
        if ($stored !== '' && $stored[0] === '[') {
            try {
                $decoded = json_decode($stored, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return [];
            }
            if (!is_array($decoded)) {
                return [];
            }
            $out = [];
            foreach ($decoded as $v) {
                if (is_int($v) && $v > 0) {
                    $out[] = $v;
                } elseif (is_string($v) && ctype_digit($v)) {
                    $out[] = (int) $v;
                }
            }

            return self::dedupeIds($out);
        }

        $parts = preg_split('/[\s,;]+/', $stored) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $p = trim((string) $p);
            if ($p !== '' && ctype_digit($p)) {
                $out[] = (int) $p;
            }
        }

        return self::dedupeIds($out);
    }

    /**
     * @param list<int> $ids
     * @return list<int> unique, first occurrence wins
     */
    public static function dedupeIds(array $ids): array
    {
        $seen = [];
        $out = [];
        foreach ($ids as $i) {
            $i = (int) $i;
            if ($i < 1 || isset($seen[$i])) {
                continue;
            }
            $seen[$i] = true;
            $out[] = $i;
        }

        return $out;
    }

    /**
     * @param list<int> $ids
     */
    public static function toJson(array $ids): string
    {
        $ids = self::dedupeIds($ids);

        return json_encode($ids, JSON_THROW_ON_ERROR);
    }
}
