<?php

declare(strict_types=1);

namespace App\Content;

use PDO;

final class ContentEntryRevisionRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array<string, mixed> $entryRow raw cms_content_entries row
     * @param array<int, string|null> $valuesByFieldId
     */
    public function capture(int $entryId, array $entryRow, array $valuesByFieldId, ?int $createdBy): void
    {
        $payload = [
            'entry' => $entryRow,
            'values' => $valuesByFieldId,
        ];
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $stmt = $this->pdo->prepare(
            'INSERT INTO cms_content_entry_revisions (content_entry_id, snapshot_json, created_by) VALUES (?, ?, ?)'
        );
        $stmt->execute([$entryId, $json, $createdBy]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForEntry(int $entryId, int $limit = 50): array
    {
        $limit = max(1, min(100, $limit));
        $stmt = $this->pdo->prepare(
            'SELECT r.*, u.email AS author_email, u.display_name AS author_name
             FROM cms_content_entry_revisions r
             LEFT JOIN cms_users u ON u.id = r.created_by
             WHERE r.content_entry_id = ?
             ORDER BY r.created_at DESC
             LIMIT ' . (int) $limit
        );
        $stmt->execute([$entryId]);
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[] = $row;
        }

        return $out;
    }

    public function findById(int $revisionId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM cms_content_entry_revisions WHERE id = ? LIMIT 1');
        $stmt->execute([$revisionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }
}
