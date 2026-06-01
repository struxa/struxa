<?php

declare(strict_types=1);

namespace App\Content;

use App\Search\ContentSearchService;
use PDO;

/**
 * Staff entry search for entry_refs pickers (title, slug, id).
 *
 * @phpstan-type PickerItem array{
 *     id: int,
 *     title: string,
 *     slug: string,
 *     status: string,
 *     content_type_id: int,
 *     type_slug: string,
 *     type_name: string
 * }
 */
final class ContentEntryPickerService
{
    public const MIN_QUERY_LENGTH = 1;
    public const DEFAULT_LIMIT = 15;
    public const MAX_LIMIT = 30;

    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    /**
     * @return list<PickerItem>
     */
    public function search(
        string $query,
        ?int $contentTypeId,
        ?int $excludeEntryId,
        int $limit = self::DEFAULT_LIMIT,
    ): array {
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $query = trim($query);
        $excludeEntryId = $excludeEntryId !== null && $excludeEntryId > 0 ? $excludeEntryId : null;

        if ($query !== '' && ctype_digit($query)) {
            return $this->fetchById((int) $query, $contentTypeId, $excludeEntryId);
        }

        if ($query === '' || mb_strlen($query) < self::MIN_QUERY_LENGTH) {
            return [];
        }

        $sanitized = ContentSearchService::sanitizeQuery($query);
        if ($sanitized === '') {
            return [];
        }

        $like = '%' . ContentSearchService::escapeLike($sanitized) . '%';
        $params = [$like, $like, $like];
        $where = 'e.deleted_at IS NULL AND t.has_public_route = 1';
        if ($contentTypeId !== null && $contentTypeId > 0) {
            $where .= ' AND e.content_type_id = ?';
            $params[] = $contentTypeId;
        }
        if ($excludeEntryId !== null) {
            $where .= ' AND e.id <> ?';
            $params[] = $excludeEntryId;
        }

        $sql = 'SELECT e.id, e.title, e.slug, e.status, e.content_type_id, t.slug AS type_slug, t.name AS type_name
                FROM cms_content_entries e
                INNER JOIN cms_content_types t ON t.id = e.content_type_id
                WHERE ' . $where . '
                  AND (e.title LIKE ? ESCAPE \'\\\\\' OR e.slug LIKE ? ESCAPE \'\\\\\' OR CAST(e.id AS CHAR) LIKE ? ESCAPE \'\\\\\')
                ORDER BY e.updated_at DESC, e.id DESC
                LIMIT ' . (int) $limit;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $this->rowsToItems($stmt);
    }

    /**
     * @return list<PickerItem>
     */
    private function fetchById(int $id, ?int $contentTypeId, ?int $excludeEntryId): array
    {
        if ($excludeEntryId !== null && $id === $excludeEntryId) {
            return [];
        }
        $params = [$id];
        $where = 'e.id = ? AND e.deleted_at IS NULL AND t.has_public_route = 1';
        if ($contentTypeId !== null && $contentTypeId > 0) {
            $where .= ' AND e.content_type_id = ?';
            $params[] = $contentTypeId;
        }
        $sql = 'SELECT e.id, e.title, e.slug, e.status, e.content_type_id, t.slug AS type_slug, t.name AS type_name
                FROM cms_content_entries e
                INNER JOIN cms_content_types t ON t.id = e.content_type_id
                WHERE ' . $where . ' LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $this->rowsToItems($stmt);
    }

    /**
     * @return list<PickerItem>
     */
    private function rowsToItems(\PDOStatement $stmt): array
    {
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!is_array($row)) {
                continue;
            }
            $out[] = [
                'id' => (int) ($row['id'] ?? 0),
                'title' => (string) ($row['title'] ?? ''),
                'slug' => (string) ($row['slug'] ?? ''),
                'status' => (string) ($row['status'] ?? ''),
                'content_type_id' => (int) ($row['content_type_id'] ?? 0),
                'type_slug' => (string) ($row['type_slug'] ?? ''),
                'type_name' => (string) ($row['type_name'] ?? ''),
            ];
        }

        return $out;
    }
}
