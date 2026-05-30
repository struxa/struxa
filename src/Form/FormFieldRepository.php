<?php

declare(strict_types=1);

namespace App\Form;

use PDO;

final class FormFieldRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForForm(int $formId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM cms_form_fields WHERE form_id = ? ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute([$formId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map([$this, 'decodeRow'], $rows);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $fieldId, int $formId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM cms_form_fields WHERE id = ? AND form_id = ? LIMIT 1'
        );
        $stmt->execute([$fieldId, $formId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->decodeRow($row) : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO cms_form_fields
                (form_id, field_key, field_type, label, placeholder, help_text, required,
                 options_json, sort_order, page_number, settings_json, conditional_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            (int) $data['form_id'],
            $data['field_key'],
            $data['field_type'],
            $data['label'],
            $data['placeholder'] ?? null,
            $data['help_text'] ?? null,
            !empty($data['required']) ? 1 : 0,
            $this->encodeOptions($data['options_json'] ?? null),
            (int) ($data['sort_order'] ?? 0),
            max(1, (int) ($data['page_number'] ?? 1)),
            $this->encodeJson($data['settings'] ?? $data['settings_json'] ?? null),
            $this->encodeJson($data['conditional'] ?? $data['conditional_json'] ?? null),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $fieldId, int $formId, array $data): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE cms_form_fields SET
                field_key = ?, field_type = ?, label = ?, placeholder = ?, help_text = ?,
                required = ?, options_json = ?, sort_order = ?, page_number = ?,
                settings_json = ?, conditional_json = ?
             WHERE id = ? AND form_id = ?'
        );

        return $stmt->execute([
            $data['field_key'],
            $data['field_type'],
            $data['label'],
            $data['placeholder'] ?? null,
            $data['help_text'] ?? null,
            !empty($data['required']) ? 1 : 0,
            $this->encodeOptions($data['options_json'] ?? null),
            (int) ($data['sort_order'] ?? 0),
            max(1, (int) ($data['page_number'] ?? 1)),
            $this->encodeJson($data['settings'] ?? $data['settings_json'] ?? null),
            $this->encodeJson($data['conditional'] ?? $data['conditional_json'] ?? null),
            $fieldId,
            $formId,
        ]);
    }

    public function delete(int $fieldId, int $formId): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM cms_form_fields WHERE id = ? AND form_id = ?');

        return $stmt->execute([$fieldId, $formId]);
    }

    /**
     * @param list<int> $orderedFieldIds
     */
    public function reorder(int $formId, array $orderedFieldIds): void
    {
        $order = 10;
        foreach ($orderedFieldIds as $fieldId) {
            $fieldId = (int) $fieldId;
            if ($fieldId < 1) {
                continue;
            }
            $stmt = $this->pdo->prepare(
                'UPDATE cms_form_fields SET sort_order = ? WHERE id = ? AND form_id = ?'
            );
            $stmt->execute([$order, $fieldId, $formId]);
            $order += 10;
        }
    }

    public function ensureHoneypot(int $formId): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM cms_form_fields WHERE form_id = ? AND field_type = ? LIMIT 1'
        );
        $stmt->execute([$formId, FormFieldType::HONEYPOT]);
        if ($stmt->fetchColumn() !== false) {
            return;
        }

        $this->create([
            'form_id' => $formId,
            'field_key' => '_hp_url',
            'field_type' => FormFieldType::HONEYPOT,
            'label' => 'Leave blank',
            'required' => 0,
            'sort_order' => 9999,
            'page_number' => 1,
        ]);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function decodeRow(array $row): array
    {
        if (isset($row['options_json']) && is_string($row['options_json']) && $row['options_json'] !== '') {
            $decoded = json_decode($row['options_json'], true);
            $row['options'] = is_array($decoded) ? array_values(array_filter(array_map('strval', $decoded))) : [];
        } else {
            $row['options'] = [];
        }

        $row['settings'] = $this->decodeJson($row['settings_json'] ?? null) ?? [];
        $row['conditional'] = $this->decodeJson($row['conditional_json'] ?? null) ?? [];

        return $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJson(?string $json): ?array
    {
        if ($json === null || trim($json) === '') {
            return null;
        }
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function encodeJson(mixed $data): ?string
    {
        if ($data === null || $data === '') {
            return null;
        }
        if (is_string($data)) {
            return trim($data) === '' ? null : $data;
        }
        if (!is_array($data)) {
            return null;
        }
        if ($data === []) {
            return null;
        }

        return json_encode($data, JSON_THROW_ON_ERROR);
    }

    /**
     * @param mixed $options
     */
    private function encodeOptions(mixed $options): ?string
    {
        if ($options === null || $options === '') {
            return null;
        }
        if (is_string($options)) {
            $lines = preg_split('/\R/', $options) ?: [];
            $list = array_values(array_filter(array_map(static fn (string $l): string => trim($l), $lines), static fn (string $l): bool => $l !== ''));

            return $list === [] ? null : json_encode($list, JSON_THROW_ON_ERROR);
        }
        if (is_array($options)) {
            $list = array_values(array_filter(array_map('strval', $options), static fn (string $v): bool => trim($v) !== ''));

            return $list === [] ? null : json_encode($list, JSON_THROW_ON_ERROR);
        }

        return null;
    }
}
