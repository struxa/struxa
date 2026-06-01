<?php

declare(strict_types=1);

namespace App\Revisions;

use App\Content\ContentField;
use App\Support\LineDiff;

/**
 * Human-readable revision diff for content entries (Drupal-style editorial compare).
 */
final class ContentEntryRevisionCompare
{
    /**
     * @param array{entry: array<string, mixed>, values: array<int|string, string|null>, sections: list<mixed>} $left
     * @param array{entry: array<string, mixed>, values: array<int|string, string|null>, sections: list<mixed>} $right
     * @param list<ContentField> $fields
     * @return array{
     *     metadata_changes: list<array{label: string, left: string, right: string, changed: bool}>,
     *     field_changes: list<array{label: string, field_key: string, left: string, right: string, changed: bool}>,
     *     sections: array{left: int, right: int, changed: bool},
     *     change_count: int,
     *     unified_diff: string,
     *     has_changes: bool
     * }
     */
    public function compare(array $left, array $right, array $fields): array
    {
        $metadata = $this->metadataChanges($left['entry'], $right['entry']);
        $fieldChanges = $this->fieldChanges($left['values'], $right['values'], $fields);
        $leftSections = RevisionSnapshot::sectionCount($left);
        $rightSections = RevisionSnapshot::sectionCount($right);
        $sections = [
            'left' => $leftSections,
            'right' => $rightSections,
            'changed' => $leftSections !== $rightSections,
        ];

        $changeCount = count(array_filter($metadata, static fn (array $r): bool => $r['changed']))
            + count(array_filter($fieldChanges, static fn (array $r): bool => $r['changed']))
            + ($sections['changed'] ? 1 : 0);

        $diffBlob = $this->buildLabeledValuesBlob($left, $right, $fields);
        $unified = implode("\n", LineDiff::unified($diffBlob['left'], $diffBlob['right']));

        return [
            'metadata_changes' => $metadata,
            'field_changes' => $fieldChanges,
            'sections' => $sections,
            'change_count' => $changeCount,
            'unified_diff' => $unified,
            'has_changes' => $changeCount > 0 || $unified !== '  (no line differences)',
        ];
    }

    /**
     * @param array<string, mixed> $leftEntry
     * @param array<string, mixed> $rightEntry
     * @return list<array{label: string, left: string, right: string, changed: bool}>
     */
    private function metadataChanges(array $leftEntry, array $rightEntry): array
    {
        $defs = [
            ['label' => 'Title', 'key' => 'title'],
            ['label' => 'URL slug', 'key' => 'slug'],
            ['label' => 'Status', 'key' => 'status'],
            ['label' => 'Published at', 'key' => 'published_at'],
            ['label' => 'SEO title', 'key' => 'seo_title'],
            ['label' => 'SEO description', 'key' => 'seo_description'],
            ['label' => 'Focus keyphrase', 'key' => 'focus_keyphrase'],
            ['label' => 'Featured image', 'key' => 'featured_image_id'],
            ['label' => 'SEO noindex', 'key' => 'seo_noindex', 'bool' => true],
        ];
        $out = [];
        foreach ($defs as $def) {
            $key = $def['key'];
            $lv = $leftEntry[$key] ?? null;
            $rv = $rightEntry[$key] ?? null;
            if (!empty($def['bool'])) {
                $ls = !empty($lv) ? 'Yes' : 'No';
                $rs = !empty($rv) ? 'Yes' : 'No';
            } else {
                $ls = $this->displayScalar($lv);
                $rs = $this->displayScalar($rv);
            }
            $out[] = [
                'label' => $def['label'],
                'left' => $ls,
                'right' => $rs,
                'changed' => $ls !== $rs,
            ];
        }

        return $out;
    }

    /**
     * @param array<int|string, string|null> $leftValues
     * @param array<int|string, string|null> $rightValues
     * @param list<ContentField> $fields
     * @return list<array{label: string, field_key: string, left: string, right: string, changed: bool}>
     */
    private function fieldChanges(array $leftValues, array $rightValues, array $fields): array
    {
        $out = [];
        foreach ($fields as $field) {
            $fid = $field->id;
            $lv = array_key_exists($fid, $leftValues) ? $leftValues[$fid] : (array_key_exists((string) $fid, $leftValues) ? $leftValues[(string) $fid] : null);
            $rv = array_key_exists($fid, $rightValues) ? $rightValues[$fid] : (array_key_exists((string) $fid, $rightValues) ? $rightValues[(string) $fid] : null);
            $ls = $this->displayFieldValue($field, $lv);
            $rs = $this->displayFieldValue($field, $rv);
            $out[] = [
                'label' => $field->label,
                'field_key' => $field->fieldKey,
                'left' => $ls,
                'right' => $rs,
                'changed' => $ls !== $rs,
            ];
        }

        return $out;
    }

    /**
     * @param array{entry: array<string, mixed>, values: array<int|string, string|null>, sections: list<mixed>} $left
     * @param array{entry: array<string, mixed>, values: array<int|string, string|null>, sections: list<mixed>} $right
     * @param list<ContentField> $fields
     * @return array{left: string, right: string}
     */
    private function buildLabeledValuesBlob(array $left, array $right, array $fields): array
    {
        $linesLeft = ['=== Entry ==='];
        $linesRight = ['=== Entry ==='];
        foreach ($this->metadataChanges($left['entry'], $right['entry']) as $row) {
            $linesLeft[] = $row['label'] . ': ' . $row['left'];
            $linesRight[] = $row['label'] . ': ' . $row['right'];
        }
        $linesLeft[] = '=== Custom fields ===';
        $linesRight[] = '=== Custom fields ===';
        foreach ($this->fieldChanges($left['values'], $right['values'], $fields) as $row) {
            $linesLeft[] = $row['label'] . ' (' . $row['field_key'] . '): ' . $row['left'];
            $linesRight[] = $row['label'] . ' (' . $row['field_key'] . '): ' . $row['right'];
        }
        $linesLeft[] = '=== Page builder blocks: ' . RevisionSnapshot::sectionCount($left) . ' ===';
        $linesRight[] = '=== Page builder blocks: ' . RevisionSnapshot::sectionCount($right) . ' ===';

        return [
            'left' => implode("\n", $linesLeft),
            'right' => implode("\n", $linesRight),
        ];
    }

    private function displayFieldValue(ContentField $field, mixed $raw): string
    {
        if ($raw === null || (is_string($raw) && trim($raw) === '')) {
            return '(empty)';
        }
        $str = is_string($raw) ? $raw : (string) $raw;
        if ($field->fieldType === 'boolean') {
            return $str === '1' ? 'Yes' : 'No';
        }
        if ($field->fieldType === 'image' && ctype_digit(trim($str))) {
            return 'Media #' . trim($str);
        }
        if ($field->fieldType === 'entry_refs') {
            return $this->truncate($str, 120);
        }
        if ($field->fieldType === 'richtext') {
            $plain = trim(strip_tags($str));

            return $this->truncate($plain !== '' ? $plain : '(HTML only)', 200);
        }

        return $this->truncate($str, 200);
    }

    private function displayScalar(mixed $v): string
    {
        if ($v === null || $v === '') {
            return '(empty)';
        }
        if (is_bool($v)) {
            return $v ? 'Yes' : 'No';
        }

        return $this->truncate((string) $v, 160);
    }

    private function truncate(string $s, int $max): string
    {
        $s = trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
        if (mb_strlen($s) > $max) {
            return mb_substr($s, 0, $max - 1) . '…';
        }

        return $s;
    }
}
