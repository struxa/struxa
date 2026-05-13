<?php

declare(strict_types=1);

namespace App\Taxonomy;

use PDO;

/**
 * Published entry rows for a taxonomy term archive (pagination-ready).
 */
final class TaxonomyArchiveQuery
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function countPublishedForTerm(int $termId): int
    {
        $vis = "e.status = 'published' AND (e.published_at IS NULL OR e.published_at <= NOW(6))";
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(DISTINCT e.id) FROM cms_content_entries e
             INNER JOIN cms_content_entry_taxonomy_terms j ON j.content_entry_id = e.id
             WHERE j.taxonomy_term_id = ? AND ' . $vis
        );
        $stmt->execute([$termId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function publishedEntriesForTermPaged(int $termId, int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(50, $perPage));
        $offset = ($page - 1) * $perPage;

        $vis = "e.status = 'published' AND (e.published_at IS NULL OR e.published_at <= NOW(6))";
        $sql = 'SELECT DISTINCT e.* FROM cms_content_entries e
                INNER JOIN cms_content_entry_taxonomy_terms j ON j.content_entry_id = e.id
                WHERE j.taxonomy_term_id = ? AND ' . $vis . '
                ORDER BY e.published_at DESC, e.updated_at DESC
                LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$termId]);
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[] = $row;
        }

        return $out;
    }
}
