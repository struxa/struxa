<?php

declare(strict_types=1);

namespace App\Access;

use PDO;

final class MemberAccessRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return list<int>
     */
    public function roleIdsForPage(int $pageId): array
    {
        $stmt = $this->pdo->prepare('SELECT role_id FROM cms_page_roles WHERE page_id = ? ORDER BY role_id ASC');
        $stmt->execute([$pageId]);

        return $this->intList($stmt);
    }

    /**
     * @return list<int>
     */
    public function roleIdsForEntry(int $entryId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT role_id FROM cms_content_entry_roles WHERE content_entry_id = ? ORDER BY role_id ASC'
        );
        $stmt->execute([$entryId]);

        return $this->intList($stmt);
    }

    /**
     * @param list<int> $roleIds
     */
    public function replacePageRoles(int $pageId, array $roleIds): void
    {
        $this->pdo->prepare('DELETE FROM cms_page_roles WHERE page_id = ?')->execute([$pageId]);
        $this->insertRoles('cms_page_roles', 'page_id', $pageId, $roleIds);
    }

    /**
     * @param list<int> $roleIds
     */
    public function replaceEntryRoles(int $entryId, array $roleIds): void
    {
        $this->pdo->prepare('DELETE FROM cms_content_entry_roles WHERE content_entry_id = ?')->execute([$entryId]);
        $this->insertRoles('cms_content_entry_roles', 'content_entry_id', $entryId, $roleIds);
    }

    public function copyPageRoles(int $fromPageId, int $toPageId): void
    {
        $this->replacePageRoles($toPageId, $this->roleIdsForPage($fromPageId));
    }

    public function copyEntryRoles(int $fromEntryId, int $toEntryId): void
    {
        $this->replaceEntryRoles($toEntryId, $this->roleIdsForEntry($fromEntryId));
    }

    /**
     * @param list<int> $roleIds
     */
    private function insertRoles(string $table, string $subjectColumn, int $subjectId, array $roleIds): void
    {
        if ($roleIds === []) {
            return;
        }
        $sql = 'INSERT IGNORE INTO ' . $table . ' (' . $subjectColumn . ', role_id) VALUES (?, ?)';
        $stmt = $this->pdo->prepare($sql);
        $seen = [];
        foreach ($roleIds as $roleId) {
            $roleId = (int) $roleId;
            if ($roleId < 1 || isset($seen[$roleId])) {
                continue;
            }
            $seen[$roleId] = true;
            $stmt->execute([$subjectId, $roleId]);
        }
    }

    /**
     * @return list<int>
     */
    private function intList(\PDOStatement $stmt): array
    {
        $out = [];
        while ($id = $stmt->fetchColumn()) {
            $out[] = (int) $id;
        }

        return $out;
    }
}
