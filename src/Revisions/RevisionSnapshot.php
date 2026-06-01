<?php

declare(strict_types=1);

namespace App\Revisions;

/**
 * Parse revision snapshot_json for content entries.
 */
final class RevisionSnapshot
{
    /**
     * @return array{entry: array<string, mixed>, values: array<int|string, string|null>, sections: list<mixed>}
     */
    public static function parseEntry(string $json): array
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['entry' => [], 'values' => [], 'sections' => []];
        }
        if (!is_array($data)) {
            return ['entry' => [], 'values' => [], 'sections' => []];
        }
        $entry = isset($data['entry']) && is_array($data['entry']) ? $data['entry'] : [];
        $values = isset($data['values']) && is_array($data['values']) ? $data['values'] : [];
        $sections = isset($data['sections']) && is_array($data['sections']) ? $data['sections'] : [];

        return ['entry' => $entry, 'values' => $values, 'sections' => $sections];
    }

    /**
     * @param array<string, mixed> $entryRow
     * @param array<int, string|null> $valuesByFieldId
     * @param list<mixed> $sections
     * @return array{entry: array<string, mixed>, values: array<int|string, string|null>, sections: list<mixed>}
     */
    public static function entryPack(array $entryRow, array $valuesByFieldId, array $sections = []): array
    {
        return [
            'entry' => $entryRow,
            'values' => $valuesByFieldId,
            'sections' => $sections,
        ];
    }

    /**
     * @param array{entry: array<string, mixed>, values: array<int|string, string|null>, sections: list<mixed>} $snap
     * @return array{status: string, title: string, slug: string}
     */
    public static function entrySummary(array $snap): array
    {
        $e = $snap['entry'];

        return [
            'status' => (string) ($e['status'] ?? ''),
            'title' => (string) ($e['title'] ?? ''),
            'slug' => (string) ($e['slug'] ?? ''),
        ];
    }

    public static function sectionCount(array $snap): int
    {
        $sections = $snap['sections'] ?? [];
        if (!is_array($sections)) {
            return 0;
        }

        return count($sections);
    }
}
