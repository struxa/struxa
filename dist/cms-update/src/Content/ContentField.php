<?php

declare(strict_types=1);

namespace App\Content;

final class ContentField
{
    public function __construct(
        public readonly int $id,
        public readonly int $contentTypeId,
        public readonly string $label,
        public readonly string $fieldKey,
        public readonly string $fieldType,
        public readonly ?string $placeholder,
        public readonly ?string $helpText,
        public readonly bool $isRequired,
        public readonly ?string $defaultValue,
        public readonly ?string $optionsJson,
        public readonly int $sortOrder,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['content_type_id'],
            (string) $row['label'],
            (string) $row['field_key'],
            (string) $row['field_type'],
            isset($row['placeholder']) && $row['placeholder'] !== '' ? (string) $row['placeholder'] : null,
            isset($row['help_text']) && $row['help_text'] !== '' ? (string) $row['help_text'] : null,
            (bool) ((int) ($row['is_required'] ?? 0)),
            isset($row['default_value']) && $row['default_value'] !== '' ? (string) $row['default_value'] : null,
            isset($row['options_json']) && $row['options_json'] !== '' ? (string) $row['options_json'] : null,
            (int) ($row['sort_order'] ?? 0),
            (string) ($row['created_at'] ?? ''),
            (string) ($row['updated_at'] ?? ''),
        );
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function selectOptions(): array
    {
        if ($this->fieldType !== 'select' || $this->optionsJson === null || $this->optionsJson === '') {
            return [];
        }
        try {
            $decoded = json_decode($this->optionsJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $item) {
            if (is_string($item)) {
                $out[] = ['value' => $item, 'label' => $item];
                continue;
            }
            if (is_array($item) && isset($item['value'], $item['label'])) {
                $out[] = ['value' => (string) $item['value'], 'label' => (string) $item['label']];
            }
        }

        return $out;
    }
}
