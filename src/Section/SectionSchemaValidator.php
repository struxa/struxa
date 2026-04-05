<?php

declare(strict_types=1);

namespace App\Section;

use App\Page\PageContentSanitizer;

/**
 * Validates and normalizes section payloads against SectionManager schemas.
 */
final class SectionSchemaValidator
{
    public function __construct(
        private readonly SectionManager $sections,
    ) {
    }

    /**
     * @param array<string, mixed> $rawData
     * @param array<string, mixed> $rawOptions
     * @return array{errors: list<string>, data: array<string, mixed>, options: array<string, mixed>}
     */
    public function validate(string $sectionKey, array $rawData, array $rawOptions = []): array
    {
        $def = $this->sections->definition($sectionKey);
        if ($def === null) {
            return ['errors' => ['Unknown section type.'], 'data' => [], 'options' => []];
        }

        $errors = [];
        $data = [];
        $san = PageContentSanitizer::fromEnv();

        foreach ($def['schema'] as $field) {
            $key = (string) ($field['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $type = (string) ($field['type'] ?? 'string');
            $required = !empty($field['required']);
            $raw = $rawData[$key] ?? null;

            $defaultVal = $def['defaults'][$key] ?? null;
            $parsed = $this->parseField($sectionKey, $type, $field, $raw, $san, $required, $errors, $key, $defaultVal);
            if ($parsed !== null) {
                $data[$key] = $parsed;
            } elseif ($required) {
                $errors[] = 'Field required: ' . $key;
            } else {
                $data[$key] = $defaultVal;
            }
        }

        $options = $this->validateOptions($def['option_schema'], $def['option_defaults'], $rawOptions, $errors);

        return ['errors' => $errors, 'data' => $data, 'options' => $options];
    }

    /**
     * @param array<string, mixed> $field
     * @param list<string> $errors
     */
    private function parseField(
        string $sectionKey,
        string $type,
        array $field,
        mixed $raw,
        PageContentSanitizer $san,
        bool $required,
        array &$errors,
        string $key,
        mixed $defaultVal,
    ): mixed {
        if ($type === 'json') {
            $decoded = $this->decodeJsonValue($raw);
            if ($decoded === null && $raw !== null && $raw !== '' && $raw !== []) {
                $errors[] = 'Invalid JSON for: ' . $key;

                return null;
            }
            if ($decoded === null) {
                return $required ? null : (is_array($defaultVal) ? $defaultVal : []);
            }

            return $this->normalizeJsonField($sectionKey, $key, $decoded, $san, $errors, $required);
        }

        if ($raw === null || $raw === '') {
            return $required ? null : $this->emptyScalar($type);
        }

        return match ($type) {
            'string' => $this->parseString($field, $raw, $errors, $key),
            'text' => $this->parseText($field, $raw, $errors, $key),
            'html' => $san->sanitize((string) $raw),
            'url' => $this->parseUrl($raw, $errors, $key),
            'bool' => filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false,
            'int' => (int) $raw,
            'image_id' => $this->parseImageId($raw, $errors, $key),
            default => (string) $raw,
        };
    }

    private function emptyScalar(string $type): mixed
    {
        return match ($type) {
            'bool' => false,
            'int' => 0,
            'image_id' => null,
            default => '',
        };
    }

    /**
     * @param array<string, mixed> $field
     * @param list<string> $errors
     */
    private function parseString(array $field, mixed $raw, array &$errors, string $key): string
    {
        $s = trim((string) $raw);
        $max = isset($field['max']) ? (int) $field['max'] : 2000;
        if ($max > 0 && strlen($s) > $max) {
            $errors[] = $key . ' exceeds max length.';

            return substr($s, 0, $max);
        }
        if (isset($field['enum']) && is_array($field['enum']) && $field['enum'] !== [] && !in_array($s, $field['enum'], true)) {
            $errors[] = 'Invalid value for: ' . $key;

            return (string) ($field['enum'][0] ?? $s);
        }

        return $s;
    }

    /**
     * @param array<string, mixed> $field
     * @param list<string> $errors
     */
    private function parseText(array $field, mixed $raw, array &$errors, string $key): string
    {
        $s = trim((string) $raw);
        $max = isset($field['max']) ? (int) $field['max'] : 10000;
        if ($max > 0 && strlen($s) > $max) {
            $errors[] = $key . ' exceeds max length.';

            return substr($s, 0, $max);
        }

        return $s;
    }

    /**
     * @param list<string> $errors
     */
    private function parseUrl(mixed $raw, array &$errors, string $key): string
    {
        $s = trim((string) $raw);
        if ($s === '') {
            return '';
        }
        if (!filter_var($s, FILTER_VALIDATE_URL) && !str_starts_with($s, '/') && !str_starts_with($s, '#')) {
            $errors[] = 'Invalid URL for: ' . $key;
        }

        return $s;
    }

    /**
     * @param list<string> $errors
     */
    private function parseImageId(mixed $raw, array &$errors, string $key): ?int
    {
        if ($raw === null || $raw === '' || $raw === false) {
            return null;
        }
        $id = (int) $raw;
        if ($id < 1) {
            $errors[] = 'Invalid media id for: ' . $key;

            return null;
        }

        return $id;
    }

    private function decodeJsonValue(mixed $raw): mixed
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }
        try {
            return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
    }

    /**
     * @param list<string> $errors
     * @return array<mixed>
     */
    private function normalizeJsonField(
        string $sectionKey,
        string $key,
        mixed $decoded,
        PageContentSanitizer $san,
        array &$errors,
        bool $required,
    ): array {
        if (!is_array($decoded)) {
            $errors[] = 'Expected array for: ' . $key;

            return [];
        }

        if ($key === 'items_json') {
            return $sectionKey === 'faq'
                ? $this->normalizeFaqItems($decoded, $san, $errors, $key, $required)
                : $this->normalizeFeatureItems($decoded, $errors, $key, $required);
        }

        return match ($key) {
            'plans_json' => $this->normalizePlans($decoded, $errors, $key, $required),
            'quotes_json' => $this->normalizeQuotes($decoded, $errors, $key, $required),
            'stats_json' => $this->normalizeStats($decoded, $errors, $key, $required),
            'queue_stats_json' => $this->normalizeQueueStatBar($decoded, $errors, $key, $required),
            'badges_json', 'chips_json' => $this->normalizeStringList($decoded, $errors, $key, $required),
            'columns_json' => $this->normalizeStringList($decoded, $errors, $key, $required),
            'rows_json' => $this->normalizeComparisonRows($decoded, $errors, $key, $required),
            default => $decoded,
        };
    }

    /**
     * @param array<mixed> $decoded
     * @return list<array{title: string, body: string, icon_media_id: int|null}>
     */
    private function normalizeFeatureItems(array $decoded, array &$errors, string $key, bool $required): array
    {
        $out = [];
        foreach ($decoded as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            $title = isset($row['title']) ? trim((string) $row['title']) : '';
            $body = isset($row['body']) ? trim((string) $row['body']) : '';
            $icon = $row['icon_media_id'] ?? null;
            $iconId = $icon !== null && $icon !== '' ? (int) $icon : null;
            if ($iconId !== null && $iconId < 1) {
                $iconId = null;
            }
            if ($title === '' && $body === '') {
                continue;
            }
            $out[] = ['title' => $title, 'body' => $body, 'icon_media_id' => $iconId];
        }
        if ($out === [] && $required) {
            $errors[] = $key . ' must contain at least one item.';
        }

        return $out;
    }

    /**
     * @param array<mixed> $decoded
     * @return list<array<string, mixed>>
     */
    private function normalizePlans(array $decoded, array &$errors, string $key, bool $required): array
    {
        $out = [];
        foreach ($decoded as $row) {
            if (!is_array($row)) {
                continue;
            }
            $bullets = [];
            if (isset($row['bullets']) && is_array($row['bullets'])) {
                foreach ($row['bullets'] as $b) {
                    $bullets[] = trim((string) $b);
                }
            }
            $out[] = [
                'name' => trim((string) ($row['name'] ?? '')),
                'price' => trim((string) ($row['price'] ?? '')),
                'cadence' => trim((string) ($row['cadence'] ?? '')),
                'bullets' => $bullets,
                'cta_label' => trim((string) ($row['cta_label'] ?? '')),
                'cta_url' => trim((string) ($row['cta_url'] ?? '')),
                'highlighted' => !empty($row['highlighted']),
            ];
        }
        if ($out === [] && $required) {
            $errors[] = $key . ' must contain at least one plan.';
        }

        return $out;
    }

    /**
     * @param array<mixed> $decoded
     * @return list<array{quote: string, attribution: string, role: string}>
     */
    private function normalizeQuotes(array $decoded, array &$errors, string $key, bool $required): array
    {
        $out = [];
        foreach ($decoded as $row) {
            if (!is_array($row)) {
                continue;
            }
            $q = trim((string) ($row['quote'] ?? ''));
            if ($q === '') {
                continue;
            }
            $out[] = [
                'quote' => $q,
                'attribution' => trim((string) ($row['attribution'] ?? '')),
                'role' => trim((string) ($row['role'] ?? '')),
            ];
        }
        if ($out === [] && $required) {
            $errors[] = $key . ' must contain at least one quote.';
        }

        return $out;
    }

    /**
     * @param array<mixed> $decoded
     * @return list<array{question: string, answer_html: string}>
     */
    private function normalizeFaqItems(array $decoded, PageContentSanitizer $san, array &$errors, string $key, bool $required): array
    {
        $out = [];
        foreach ($decoded as $row) {
            if (!is_array($row)) {
                continue;
            }
            $q = trim((string) ($row['question'] ?? ''));
            $a = $san->sanitize((string) ($row['answer_html'] ?? ''));
            if ($q === '' && trim(strip_tags($a)) === '') {
                continue;
            }
            $out[] = ['question' => $q, 'answer_html' => $a];
        }
        if ($out === [] && $required) {
            $errors[] = $key . ' must contain at least one entry.';
        }

        return $out;
    }

    /**
     * @param array<mixed> $decoded
     * @return list<array{value: string, label: string}>
     */
    private function normalizeStats(array $decoded, array &$errors, string $key, bool $required): array
    {
        $out = [];
        foreach ($decoded as $row) {
            if (!is_array($row)) {
                continue;
            }
            $out[] = [
                'value' => trim((string) ($row['value'] ?? '')),
                'label' => trim((string) ($row['label'] ?? '')),
            ];
        }
        if ($out === [] && $required) {
            $errors[] = $key . ' must contain at least one stat.';
        }

        return $out;
    }

    /**
     * @param array<mixed> $decoded
     * @return list<array{heading: string, text: string}>
     */
    private function normalizeQueueStatBar(array $decoded, array &$errors, string $key, bool $required): array
    {
        $out = [];
        foreach ($decoded as $row) {
            if (!is_array($row)) {
                continue;
            }
            $heading = trim((string) ($row['heading'] ?? $row['k'] ?? ''));
            $text = trim((string) ($row['text'] ?? $row['v'] ?? ''));
            if ($heading === '' && $text === '') {
                continue;
            }
            $out[] = ['heading' => $heading, 'text' => $text];
        }
        if ($out === [] && $required) {
            $errors[] = $key . ' must contain at least one stat.';
        }

        return $out;
    }

    /**
     * @param array<mixed> $decoded
     * @return list<string>
     */
    private function normalizeStringList(array $decoded, array &$errors, string $key, bool $required): array
    {
        $out = [];
        foreach ($decoded as $cell) {
            $out[] = trim((string) $cell);
        }
        $out = array_values(array_filter($out, static fn (string $s): bool => $s !== ''));
        if ($out === [] && $required) {
            $errors[] = $key . ' must list at least one column.';
        }

        return $out;
    }

    /**
     * @param array<mixed> $decoded
     * @return list<array{feature: string, cells: list<string>}>
     */
    private function normalizeComparisonRows(array $decoded, array &$errors, string $key, bool $required): array
    {
        $out = [];
        foreach ($decoded as $row) {
            if (!is_array($row)) {
                continue;
            }
            $cells = [];
            if (isset($row['cells']) && is_array($row['cells'])) {
                foreach ($row['cells'] as $c) {
                    $cells[] = trim((string) $c);
                }
            }
            $out[] = [
                'feature' => trim((string) ($row['feature'] ?? '')),
                'cells' => $cells,
            ];
        }
        if ($out === [] && $required) {
            $errors[] = $key . ' must contain at least one row.';
        }

        return $out;
    }

    /** @param list<array<string, mixed>> $optionSchema */
    private function validateOptions(array $optionSchema, array $defaults, array $raw, array &$errors): array
    {
        $out = $defaults;
        foreach ($optionSchema as $field) {
            $key = (string) ($field['key'] ?? '');
            if ($key === '') {
                continue;
            }
            if (!array_key_exists($key, $raw)) {
                continue;
            }
            $type = (string) ($field['type'] ?? 'string');
            $v = $raw[$key];
            if ($type === 'string') {
                $s = trim((string) $v);
                if (isset($field['enum']) && is_array($field['enum']) && $field['enum'] !== [] && !in_array($s, $field['enum'], true)) {
                    $errors[] = 'Invalid option: ' . $key;
                    continue;
                }
                $out[$key] = $s !== '' ? $s : ($defaults[$key] ?? '');
            }
        }

        return $out;
    }
}
