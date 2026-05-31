<?php

declare(strict_types=1);

namespace App\Revisions;

use PDO;

/**
 * Prunes excess page and entry revisions according to {@see RevisionRetentionSettings}.
 */
final class RevisionRetentionService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function prunePageRevisions(int $pageId): int
    {
        $max = RevisionRetentionSettings::pageMax();
        if ($max <= 0 || $pageId < 1) {
            return 0;
        }

        return $this->pruneTable('cms_page_revisions', 'page_id', $pageId, $max);
    }

    public function pruneEntryRevisions(int $entryId): int
    {
        $max = RevisionRetentionSettings::entryMax();
        if ($max <= 0 || $entryId < 1) {
            return 0;
        }

        return $this->pruneTable('cms_content_entry_revisions', 'content_entry_id', $entryId, $max);
    }

    /**
     * One-time sweep for maintenance — removes excess revisions for every parent row.
     *
     * @return array{page_revisions: int, entry_revisions: int}
     */
    public function pruneAllExcess(): array
    {
        $pageMax = RevisionRetentionSettings::pageMax();
        $entryMax = RevisionRetentionSettings::entryMax();
        $pageDeleted = 0;
        $entryDeleted = 0;

        if ($pageMax > 0) {
            $ids = $this->pdo->query('SELECT DISTINCT page_id FROM cms_page_revisions')->fetchAll(PDO::FETCH_COLUMN);
            foreach ($ids as $id) {
                $pageDeleted += $this->pruneTable('cms_page_revisions', 'page_id', (int) $id, $pageMax);
            }
        }

        if ($entryMax > 0) {
            $ids = $this->pdo->query('SELECT DISTINCT content_entry_id FROM cms_content_entry_revisions')->fetchAll(PDO::FETCH_COLUMN);
            foreach ($ids as $id) {
                $entryDeleted += $this->pruneTable('cms_content_entry_revisions', 'content_entry_id', (int) $id, $entryMax);
            }
        }

        return ['page_revisions' => $pageDeleted, 'entry_revisions' => $entryDeleted];
    }

    /**
     * @return array<string, int>
     */
    public function stats(): array
    {
        return [
            'page_revisions' => $this->scalarCount('SELECT COUNT(*) FROM cms_page_revisions'),
            'entry_revisions' => $this->scalarCount('SELECT COUNT(*) FROM cms_content_entry_revisions'),
            'revision_retention_page_max' => RevisionRetentionSettings::pageMax(),
            'revision_retention_entry_max' => RevisionRetentionSettings::entryMax(),
        ];
    }

    private function pruneTable(string $table, string $parentColumn, int $parentId, int $keepMax): int
    {
        $keepMax = max(1, $keepMax);
        $sql = 'DELETE FROM ' . $table . '
                WHERE ' . $parentColumn . ' = :parent_id
                  AND id NOT IN (
                    SELECT id FROM (
                      SELECT id FROM ' . $table . '
                      WHERE ' . $parentColumn . ' = :parent_id2
                      ORDER BY created_at DESC, id DESC
                      LIMIT ' . (int) $keepMax . '
                    ) kept
                  )';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':parent_id' => $parentId,
            ':parent_id2' => $parentId,
        ]);

        return max(0, $stmt->rowCount());
    }

    private function scalarCount(string $sql): int
    {
        $v = $this->pdo->query($sql);

        return $v !== false ? (int) $v->fetchColumn() : 0;
    }
}
