<?php

declare(strict_types=1);

namespace ContentStreamPlugin;

/**
 * Normalizes keyword strings for DataForSEO limits (80 chars, 10 words).
 */
final class KeywordPhraseNormalizer
{
    /**
     * @param list<string>|mixed $raw
     *
     * @return list<string>
     */
    public static function normalizeList(array $raw): array
    {
        $out = [];
        $seen = [];
        foreach ($raw as $item) {
            if (!is_string($item)) {
                continue;
            }
            $k = self::normalizeOne($item);
            if ($k === '') {
                continue;
            }
            $lk = strtolower($k);
            if (isset($seen[$lk])) {
                continue;
            }
            $seen[$lk] = true;
            $out[] = $k;
        }

        return $out;
    }

    public static function normalizeOne(string $s): string
    {
        $s = trim(preg_replace('/\s+/u', ' ', $s) ?? '');
        if ($s === '') {
            return '';
        }
        // DataForSEO: avoid symbols that break Google Ads tasks
        $s = preg_replace('/[^\p{L}\p{N}\s\-\'\.&,]/u', '', $s) ?? '';
        $s = trim($s);
        $words = preg_split('/\s+/u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($words) > 10) {
            $words = array_slice($words, 0, 10);
            $s = implode(' ', $words);
        }
        if (strlen($s) > 80) {
            $s = substr($s, 0, 80);
            $s = rtrim($s);
        }

        return $s;
    }
}
