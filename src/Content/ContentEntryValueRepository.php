<?php

declare(strict_types=1);

namespace App\Content;

use PDO;

final class ContentEntryValueRepository
{
    private const TABLE = 'cms_content_entry_values';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array<int, string> field_id => stored string
     */
    public function valuesByFieldIdForEntry(int $entryId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT field_id, value_longtext FROM ' . self::TABLE . ' WHERE content_entry_id = ?'
        );
        $stmt->execute([$entryId]);
        $map = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $map[(int) $row['field_id']] = (string) ($row['value_longtext'] ?? '');
        }

        return $map;
    }

    public function upsert(int $entryId, int $fieldId, ?string $value): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . self::TABLE . ' (content_entry_id, field_id, value_longtext)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE value_longtext = VALUES(value_longtext)'
        );
        $stmt->execute([$entryId, $fieldId, $value]);
    }

    public function deleteForEntry(int $entryId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM ' . self::TABLE . ' WHERE content_entry_id = ?');
        $stmt->execute([$entryId]);
    }

    public function deleteForField(int $fieldId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM ' . self::TABLE . ' WHERE field_id = ?');
        $stmt->execute([$fieldId]);
    }

    /**
     * @param list<int> $entryIds
     * @return array<int, string> content_entry_id => value
     */
    public function valuesForFieldAndEntryIds(int $fieldId, array $entryIds): array
    {
        $entryIds = array_values(array_filter(array_map('intval', $entryIds), static fn (int $id): bool => $id > 0));
        if ($entryIds === []) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($entryIds), '?'));
        $sql = 'SELECT content_entry_id, value_longtext FROM ' . self::TABLE
            . ' WHERE field_id = ? AND content_entry_id IN (' . $ph . ')';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_merge([$fieldId], $entryIds));
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[(int) $row['content_entry_id']] = (string) ($row['value_longtext'] ?? '');
        }

        return $out;
    }
}
