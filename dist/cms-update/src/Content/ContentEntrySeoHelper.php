<?php

declare(strict_types=1);

namespace App\Content;

/**
 * Plain-text fallback for meta descriptions when entry SEO description is empty.
 *
 * @param list<ContentField> $fields
 * @param array<int, string> $valueMap
 */
final class ContentEntrySeoHelper
{
    private const FIELD_KEYS = ['excerpt', 'summary', 'intro', 'body', 'content', 'description', 'role'];

    public static function plainDescriptionFallback(array $fields, array $valueMap): string
    {
        $byKey = [];
        foreach ($fields as $f) {
            $byKey[$f->fieldKey] = $f;
        }
        foreach (self::FIELD_KEYS as $key) {
            if (!isset($byKey[$key])) {
                continue;
            }
            $id = $byKey[$key]->id;
            $raw = trim((string) ($valueMap[$id] ?? ''));
            if ($raw === '') {
                continue;
            }
            $plain = trim(preg_replace('/\s+/', ' ', strip_tags($raw)) ?? '');

            return $plain;
        }

        return '';
    }
}
