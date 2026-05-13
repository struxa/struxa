<?php

declare(strict_types=1);

namespace GuidesPlugin;

use PDO;

/**
 * Read-only data access for the guides admin datatable.
 *
 * The CMS already ships repositories for content entries / fields / values
 * but they're field-key-agnostic — for the admin table we want a single
 * pre-joined row per guide with the featured image path and the legacy id
 * baked in. Doing that here keeps the admin route flat and the view a
 * straight foreach.
 *
 * @phpstan-type GuideRow array{
 *   id: int,
 *   title: string,
 *   slug: string,
 *   status: string,
 *   updated_at: string,
 *   legacy_id: ?int,
 *   featured_media_id: ?int,
 *   featured_media_path: ?string
 * }
 */
final class GuideEntryRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Fast lookup of the "guides" content type id. Returns null if the
     * migration hasn't been applied yet (admin route uses this to render
     * a friendly hint rather than crash).
     */
    public function typeId(): ?int
    {
        $stmt = $this->pdo->query('SELECT id FROM cms_content_types WHERE slug = "guides" LIMIT 1');
        $id = $stmt instanceof \PDOStatement ? $stmt->fetchColumn() : false;
        return $id === false || $id === null ? null : (int) $id;
    }

    /**
     * Count entries matching the admin filters.
     */
    public function countForAdmin(string $search = '', string $thumbsFilter = 'all'): int
    {
        $tid = $this->typeId();
        if ($tid === null) {
            return 0;
        }
        [$sql, $params] = $this->buildFilterClauses($tid, $search, $thumbsFilter);
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM cms_content_entries e WHERE ' . $sql
        );
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Paged list for the admin datatable.
     *
     * @return list<GuideRow>
     */
    public function listForAdmin(int $page, int $perPage, string $search = '', string $thumbsFilter = 'all'): array
    {
        $tid = $this->typeId();
        if ($tid === null) {
            return [];
        }
        $page = max(1, $page);
        $perPage = max(1, min(200, $perPage));
        $offset = ($page - 1) * $perPage;

        [$where, $params] = $this->buildFilterClauses($tid, $search, $thumbsFilter);

        $sql = '
            SELECT
                e.id,
                e.title,
                e.slug,
                e.status,
                e.updated_at,
                gi.legacy_id           AS legacy_id,
                e.featured_image_id    AS featured_media_id,
                m.path                 AS featured_media_path
              FROM cms_content_entries e
              LEFT JOIN guides_imports gi
                     ON gi.entry_id = e.id
              LEFT JOIN cms_media m
                     ON m.id = e.featured_image_id
             WHERE ' . $where . '
             ORDER BY e.updated_at DESC, e.id DESC
             LIMIT ' . $perPage . ' OFFSET ' . $offset;

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $name => $val) {
            $stmt->bindValue($name, $val);
        }
        $stmt->execute();

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $out[] = [
                'id'                 => (int) $row['id'],
                'title'              => (string) $row['title'],
                'slug'               => (string) $row['slug'],
                'status'             => (string) $row['status'],
                'updated_at'         => (string) $row['updated_at'],
                'legacy_id'          => isset($row['legacy_id']) ? (int) $row['legacy_id'] : null,
                'featured_media_id'  => isset($row['featured_media_id']) ? (int) $row['featured_media_id'] : null,
                'featured_media_path'=> isset($row['featured_media_path']) ? (string) $row['featured_media_path'] : null,
            ];
        }
        return $out;
    }

    /**
     * Find one guide by entry id. Used by the image-generation endpoint to
     * resolve the title + slug payload it needs to seed the prompt.
     *
     * @return array{id:int, title:string, slug:string, featured_media_id:?int}|null
     */
    public function findForImageJob(int $entryId): ?array
    {
        $tid = $this->typeId();
        if ($tid === null) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT id, title, slug, featured_image_id
               FROM cms_content_entries
              WHERE id = ? AND content_type_id = ? LIMIT 1'
        );
        $stmt->execute([$entryId, $tid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        return [
            'id'                => (int) $row['id'],
            'title'             => (string) $row['title'],
            'slug'              => (string) $row['slug'],
            'featured_media_id' => isset($row['featured_image_id']) ? (int) $row['featured_image_id'] : null,
        ];
    }

    /**
     * Top-of-page stats: total/published, and how many already have a
     * thumbnail attached. Used by the admin landing page.
     *
     * @return array{total:int, published:int, with_thumb:int}
     */
    public function stats(): array
    {
        $tid = $this->typeId();
        if ($tid === null) {
            return ['total' => 0, 'published' => 0, 'with_thumb' => 0];
        }
        $stmt = $this->pdo->prepare(
            'SELECT
                 COUNT(*) AS total,
                 SUM(status = "published") AS published,
                 SUM(featured_image_id IS NOT NULL) AS with_thumb
               FROM cms_content_entries
              WHERE content_type_id = ?'
        );
        $stmt->execute([$tid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'total'      => (int) ($row['total'] ?? 0),
            'published'  => (int) ($row['published'] ?? 0),
            'with_thumb' => (int) ($row['with_thumb'] ?? 0),
        ];
    }

    /**
     * Build the WHERE clause + bound parameter map shared by listForAdmin()
     * and countForAdmin(), so both queries always reflect the same filter
     * set. Returns ["sql fragment", [":name" => value, …]].
     *
     * @return array{0:string, 1:array<string, mixed>}
     */
    private function buildFilterClauses(int $typeId, string $search, string $thumbsFilter): array
    {
        $clauses = ['e.content_type_id = :type_id'];
        $params  = [':type_id' => $typeId];

        $search = trim($search);
        if ($search !== '') {
            // LIKE on title only — slug is title-derived so a title match
            // covers slug clicks too. Limit length to keep the prepared
            // statement compact.
            $params[':q'] = '%' . $this->escapeLike(mb_substr($search, 0, 80, 'UTF-8')) . '%';
            $clauses[] = 'e.title LIKE :q';
        }
        switch ($thumbsFilter) {
            case 'missing':
                $clauses[] = 'e.featured_image_id IS NULL';
                break;
            case 'has':
                $clauses[] = 'e.featured_image_id IS NOT NULL';
                break;
            default:
                // 'all' — no extra clause
                break;
        }
        return [implode(' AND ', $clauses), $params];
    }

    /** Escape SQL LIKE wildcards so user search input is treated literally. */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
