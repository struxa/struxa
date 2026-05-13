<?php

declare(strict_types=1);

namespace App\Content;

use App\Media\MediaRepository;
use App\Page\PageContentSanitizer;
use App\Page\PageValidator;

/**
 * Validates and normalizes custom field values from POST for storage in value_longtext.
 */
final class ContentFieldValueNormalizer
{
    private static ?PageContentSanitizer $richtextSanitizer = null;

    /**
     * @param list<ContentField> $fields
     * @param array<string, mixed> $body
     * @return array{errors: array<string, string>, values: array<int, string|null>}
     */
    public function normalizeAll(array $fields, array $body, MediaRepository $mediaRepo): array
    {
        $errors = [];
        $values = [];
        $custom = $body['custom_fields'] ?? [];
        if (!is_array($custom)) {
            $custom = [];
        }

        foreach ($fields as $field) {
            $key = 'field_' . $field->id;
            $hasKey = array_key_exists($field->id, $custom) || array_key_exists((string) $field->id, $custom);
            $raw = $custom[$field->id] ?? $custom[(string) $field->id] ?? null;
            $result = $this->normalizeOne($field, $raw, $mediaRepo, $hasKey);
            if ($result['error'] !== null) {
                $errors[$key] = $result['error'];
            }
            $values[$field->id] = $result['value'];
        }

        return ['errors' => $errors, 'values' => $values];
    }

    /**
     * @return array{value: ?string, error: ?string}
     */
    public function normalizeOne(ContentField $field, mixed $raw, MediaRepository $mediaRepo, bool $presentInRequest = true): array
    {
        $type = $field->fieldType;

        if ($type === 'boolean') {
            $on = $raw === true || $raw === '1' || $raw === 1 || $raw === 'on' || $raw === 'yes';
            if ($field->isRequired && !$on) {
                return ['value' => null, 'error' => 'This field is required (must be checked).'];
            }
            if (!$field->isRequired && !$on && !$presentInRequest) {
                return ['value' => $field->defaultValue ?? '0', 'error' => null];
            }

            return ['value' => $on ? '1' : '0', 'error' => null];
        }

        if ($type === 'entry_refs') {
            return $this->entryRefs($field, $raw, $presentInRequest);
        }

        if ($raw === null || (is_string($raw) && trim($raw) === '')) {
            if ($field->isRequired) {
                return ['value' => null, 'error' => 'This field is required.'];
            }

            return ['value' => $field->defaultValue, 'error' => null];
        }

        $str = is_string($raw) ? str_replace("\0", '', $raw) : (string) $raw;

        if ($type === 'richtext') {
            return $this->richtext($str, $field);
        }

        return match ($type) {
            'text', 'textarea' => $this->textLike($str, $field),
            'number' => $this->number($str, $field),
            'select' => $this->select($str, $field),
            'image' => $this->image($str, $field, $mediaRepo),
            'date' => $this->date($str, $field),
            'url' => $this->url($str, $field),
            default => ['value' => null, 'error' => 'Unknown field type.'],
        };
    }

    /**
     * @return array{value: ?string, error: ?string}
     */
    private function richtext(string $str, ContentField $field): array
    {
        $base = $this->textLike($str, $field);
        if ($base['error'] !== null) {
            return $base;
        }
        $v = $base['value'];
        if ($v === null || trim($v) === '') {
            return $base;
        }
        self::$richtextSanitizer ??= PageContentSanitizer::fromEnv();
        $sanitized = self::$richtextSanitizer->sanitize($v);
        $meaningful = PageValidator::sanitizedBodyHasMeaningfulContent($sanitized);
        if ($field->isRequired && !$meaningful) {
            return ['value' => null, 'error' => 'This field is required.'];
        }
        if (!$meaningful) {
            $d = $field->defaultValue;

            return ['value' => ($d !== null && $d !== '') ? $d : '', 'error' => null];
        }

        return ['value' => $sanitized, 'error' => null];
    }

    /**
     * @return array{value: ?string, error: ?string}
     */
    private function textLike(string $str, ContentField $field): array
    {
        $v = trim($str);
        if ($v === '' && $field->isRequired) {
            return ['value' => null, 'error' => 'This field is required.'];
        }
        if ($v === '') {
            return ['value' => $field->defaultValue, 'error' => null];
        }

        return ['value' => $str, 'error' => null];
    }

    /**
     * @return array{value: ?string, error: ?string}
     */
    private function number(string $str, ContentField $field): array
    {
        $str = trim($str);
        if ($str === '' && !$field->isRequired) {
            return ['value' => $field->defaultValue, 'error' => null];
        }
        if (!is_numeric($str)) {
            return ['value' => null, 'error' => 'Enter a valid number.'];
        }

        return ['value' => (string) (0 + $str), 'error' => null];
    }

    /**
     * @return array{value: ?string, error: ?string}
     */
    private function select(string $str, ContentField $field): array
    {
        $str = trim($str);
        $allowed = [];
        foreach ($field->selectOptions() as $o) {
            $allowed[$o['value']] = true;
        }
        if ($str === '' && !$field->isRequired) {
            return ['value' => $field->defaultValue, 'error' => null];
        }
        if ($str === '' || !isset($allowed[$str])) {
            return ['value' => null, 'error' => 'Pick a valid option.'];
        }

        return ['value' => $str, 'error' => null];
    }

    /**
     * @return array{value: ?string, error: ?string}
     */
    private function image(string $str, ContentField $field, MediaRepository $mediaRepo): array
    {
        $str = trim($str);
        if ($str === '' && !$field->isRequired) {
            return ['value' => $field->defaultValue, 'error' => null];
        }
        if ($str === '') {
            return ['value' => null, 'error' => 'Select a media ID or leave optional fields empty.'];
        }
        if (!ctype_digit($str)) {
            return ['value' => null, 'error' => 'Media ID must be a number.'];
        }
        $id = (int) $str;
        $m = $mediaRepo->findById($id);
        if ($m === null || !$m->isImage()) {
            return ['value' => null, 'error' => 'Choose a valid image from the media library.'];
        }

        return ['value' => (string) $id, 'error' => null];
    }

    /**
     * @return array{value: ?string, error: ?string}
     */
    private function date(string $str, ContentField $field): array
    {
        $str = trim($str);
        if ($str === '' && !$field->isRequired) {
            return ['value' => $field->defaultValue, 'error' => null];
        }
        if ($str === '') {
            return ['value' => null, 'error' => 'Date is required.'];
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $str);
        if ($dt === false || $dt->format('Y-m-d') !== $str) {
            return ['value' => null, 'error' => 'Use YYYY-MM-DD.'];
        }

        return ['value' => $str, 'error' => null];
    }

    /**
     * @return array{value: ?string, error: ?string}
     */
    private function entryRefs(ContentField $field, mixed $raw, bool $presentInRequest): array
    {
        $opts = ContentEntryRefsFieldOptions::fromField($field);
        $empty = $raw === null
            || (is_string($raw) && trim($raw) === '')
            || (is_array($raw) && $raw === []);
        if ($empty) {
            if ($field->isRequired) {
                return ['value' => null, 'error' => 'This field is required.'];
            }
            if (!$presentInRequest && $field->defaultValue !== null && trim((string) $field->defaultValue) !== '') {
                try {
                    return ['value' => ContentEntryReferenceIds::toJson(ContentEntryReferenceIds::parse((string) $field->defaultValue)), 'error' => null];
                } catch (\JsonException) {
                    return ['value' => '[]', 'error' => null];
                }
            }

            return ['value' => '[]', 'error' => null];
        }

        $ids = [];
        if (is_array($raw)) {
            foreach ($raw as $v) {
                if (is_int($v) && $v > 0) {
                    $ids[] = $v;
                } elseif (is_string($v) && ctype_digit(trim($v))) {
                    $ids[] = (int) trim($v);
                }
            }
        } else {
            $str = is_string($raw) ? str_replace("\0", '', (string) $raw) : (string) $raw;
            $ids = ContentEntryReferenceIds::parse($str);
        }

        $ids = ContentEntryReferenceIds::dedupeIds($ids);
        if (count($ids) > $opts->maxRefs) {
            return ['value' => null, 'error' => 'Too many linked entries (max ' . $opts->maxRefs . ').'];
        }
        try {
            $json = ContentEntryReferenceIds::toJson($ids);
        } catch (\JsonException) {
            return ['value' => null, 'error' => 'Could not store entry links.'];
        }

        return ['value' => $json, 'error' => null];
    }

    /**
     * @return array{value: ?string, error: ?string}
     */
    private function url(string $str, ContentField $field): array
    {
        $str = trim($str);
        if ($str === '' && !$field->isRequired) {
            return ['value' => $field->defaultValue, 'error' => null];
        }
        if ($str === '') {
            return ['value' => null, 'error' => 'URL is required.'];
        }
        if (!filter_var($str, FILTER_VALIDATE_URL)) {
            return ['value' => null, 'error' => 'Enter a valid URL.'];
        }

        return ['value' => $str, 'error' => null];
    }
}
