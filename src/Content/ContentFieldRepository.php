<?php

declare(strict_types=1);

namespace App\Content;

use PDO;

final class ContentFieldRepository
{
    private const TABLE = 'cms_content_fields';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return list<ContentField>
     */
    public function forTypeOrdered(int $contentTypeId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ' . self::TABLE . ' WHERE content_type_id = ? ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute([$contentTypeId]);
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[] = ContentField::fromRow($row);
        }

        return $out;
    }

    /**
     * Field counts keyed by content type id.
     *
     * @return array<int, int>
     */
    public function countsByContentType(): array
    {
        $stmt = $this->pdo->query(
            'SELECT content_type_id, COUNT(*) AS c FROM ' . self::TABLE . ' GROUP BY content_type_id'
        );
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[(int) $row['content_type_id']] = (int) $row['c'];
        }

        return $out;
    }

    public function findById(int $id): ?ContentField
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ' . self::TABLE . ' WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : ContentField::fromRow($row);
    }

    public function fieldKeyExists(int $contentTypeId, string $key, ?int $exceptFieldId = null): bool
    {
        if ($exceptFieldId === null) {
            $stmt = $this->pdo->prepare(
                'SELECT 1 FROM ' . self::TABLE . ' WHERE content_type_id = ? AND field_key = ? LIMIT 1'
            );
            $stmt->execute([$contentTypeId, $key]);
        } else {
            $stmt = $this->pdo->prepare(
                'SELECT 1 FROM ' . self::TABLE . ' WHERE content_type_id = ? AND field_key = ? AND id != ? LIMIT 1'
            );
            $stmt->execute([$contentTypeId, $key, $exceptFieldId]);
        }

        return (bool) $stmt->fetchColumn();
    }

    public function nextSortOrder(int $contentTypeId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(MAX(sort_order), -1) + 1 FROM ' . self::TABLE . ' WHERE content_type_id = ?'
        );
        $stmt->execute([$contentTypeId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return int new id
     */
    public function insert(
        int $contentTypeId,
        string $label,
        string $fieldKey,
        string $fieldType,
        ?string $placeholder,
        ?string $helpText,
        bool $isRequired,
        ?string $defaultValue,
        ?string $optionsJson,
        int $sortOrder
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . self::TABLE . '
            (content_type_id, label, field_key, field_type, placeholder, help_text, is_required, default_value, options_json, sort_order)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $contentTypeId,
            $label,
            $fieldKey,
            $fieldType,
            $placeholder,
            $helpText,
            $isRequired ? 1 : 0,
            $defaultValue,
            $optionsJson,
            $sortOrder,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function update(
        int $id,
        string $label,
        string $fieldKey,
        string $fieldType,
        ?string $placeholder,
        ?string $helpText,
        bool $isRequired,
        ?string $defaultValue,
        ?string $optionsJson,
        int $sortOrder
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE ' . self::TABLE . ' SET label = ?, field_key = ?, field_type = ?, placeholder = ?, help_text = ?,
             is_required = ?, default_value = ?, options_json = ?, sort_order = ? WHERE id = ?'
        );
        $stmt->execute([
            $label,
            $fieldKey,
            $fieldType,
            $placeholder,
            $helpText,
            $isRequired ? 1 : 0,
            $defaultValue,
            $optionsJson,
            $sortOrder,
            $id,
        ]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM ' . self::TABLE . ' WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function belongsToType(int $fieldId, int $contentTypeId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM ' . self::TABLE . ' WHERE id = ? AND content_type_id = ? LIMIT 1'
        );
        $stmt->execute([$fieldId, $contentTypeId]);

        return (bool) $stmt->fetchColumn();
    }

    public function updateSortOrder(int $fieldId, int $contentTypeId, int $sortOrder): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE ' . self::TABLE . ' SET sort_order = ? WHERE id = ? AND content_type_id = ?'
        );
        $stmt->execute([$sortOrder, $fieldId, $contentTypeId]);
    }
}
