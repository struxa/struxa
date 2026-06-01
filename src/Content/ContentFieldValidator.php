<?php

declare(strict_types=1);

namespace App\Content;

final class ContentFieldValidator
{
    /**
     * @param array<string, mixed> $body
     * @return array{errors: array<string, string>, values: array<string, mixed>}
     */
    public function validate(
        array $body,
        int $contentTypeId,
        ?int $exceptFieldId = null,
        ?ContentFieldRepository $fieldRepo = null
    ): array {
        $errors = [];

        $label = $this->str($body, 'label');
        if ($label === '') {
            $errors['label'] = 'Label is required.';
        } elseif (mb_strlen($label) > 191) {
            $errors['label'] = 'Label is too long.';
        }

        $fieldKey = $this->str($body, 'field_key');
        if ($fieldKey === '') {
            $errors['field_key'] = 'Field key is required.';
        } elseif (!preg_match('/^[a-z][a-z0-9_]{1,62}$/', $fieldKey)) {
            $errors['field_key'] = 'Use a lowercase key: start with a letter, then letters, numbers, underscores.';
        } elseif ($fieldRepo !== null && $fieldRepo->fieldKeyExists($contentTypeId, $fieldKey, $exceptFieldId)) {
            $errors['field_key'] = 'That key already exists on this content type.';
        }

        $fieldType = $this->str($body, 'field_type');
        if (!ContentFieldTypes::isValid($fieldType)) {
            $errors['field_type'] = 'Pick a valid field type.';
        }

        $placeholder = $this->nullableStr($body, 'placeholder');
        if ($placeholder !== null && mb_strlen($placeholder) > 255) {
            $errors['placeholder'] = 'Placeholder is too long.';
        }

        $helpText = $this->nullableStr($body, 'help_text');
        $isRequired = !empty($body['is_required']);
        $defaultValue = $this->nullableStr($body, 'default_value');

        $optionsJson = null;
        if ($fieldType === 'select') {
            $rawOpt = $this->str($body, 'options_json');
            if ($rawOpt === '') {
                $errors['options_json'] = 'Select fields need options (JSON array or one option per line).';
            } else {
                $parsed = $this->parseSelectOptions($rawOpt);
                if ($parsed === null) {
                    $errors['options_json'] = 'Invalid options: use JSON like [{"value":"a","label":"A"}] or one value per line.';
                } else {
                    try {
                        $optionsJson = json_encode($parsed, JSON_THROW_ON_ERROR);
                    } catch (\JsonException) {
                        $errors['options_json'] = 'Could not encode options.';
                    }
                }
            }
        } elseif ($fieldType === 'entry_refs') {
            $body = ContentEntryRefsFieldOptions::mergeStructuredIntoBody($body);
            $optCheck = ContentEntryRefsFieldOptions::validateOptionsBody($body);
            foreach ($optCheck['errors'] as $k => $msg) {
                $errors[$k] = $msg;
            }
            $optionsJson = $optCheck['json'];
        } else {
            $rawOpt = $this->str($body, 'options_json');
            if ($rawOpt !== '') {
                $errors['options_json'] = 'Options are only used for select and “entry links” fields.';
            }
        }

        $sortOrder = (int) ($body['sort_order'] ?? 0);
        if ($sortOrder < 0) {
            $errors['sort_order'] = 'Sort order cannot be negative.';
        }

        return [
            'errors' => $errors,
            'values' => [
                'label' => $label,
                'field_key' => $fieldKey,
                'field_type' => $fieldType,
                'placeholder' => $placeholder,
                'help_text' => $helpText,
                'is_required' => $isRequired,
                'default_value' => $defaultValue,
                'options_json' => $optionsJson,
                'sort_order' => $sortOrder,
            ],
        ];
    }

    /**
     * @return list<array{value: string, label: string}>|null
     */
    private function parseSelectOptions(string $raw): ?array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        if ($raw[0] === '[' || $raw[0] === '{') {
            try {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return null;
            }
            if (!is_array($decoded)) {
                return null;
            }
            $out = [];
            foreach ($decoded as $item) {
                if (is_string($item)) {
                    $out[] = ['value' => $item, 'label' => $item];
                } elseif (is_array($item) && isset($item['value'])) {
                    $out[] = [
                        'value' => (string) $item['value'],
                        'label' => (string) ($item['label'] ?? $item['value']),
                    ];
                }
            }

            return $out !== [] ? $out : null;
        }

        $lines = preg_split('/\R/', $raw) ?: [];
        $out = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $out[] = ['value' => $line, 'label' => $line];
        }

        return $out !== [] ? $out : null;
    }

    private function str(array $body, string $key): string
    {
        $v = $body[$key] ?? '';

        return trim(is_string($v) ? str_replace("\0", '', $v) : '');
    }

    private function nullableStr(array $body, string $key): ?string
    {
        $v = $this->str($body, $key);

        return $v === '' ? null : $v;
    }
}
