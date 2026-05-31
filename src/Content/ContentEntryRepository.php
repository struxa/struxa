<?php

declare(strict_types=1);

namespace App\Content;

use PDO;

final class ContentEntryRepository
{
    private const TABLE = 'cms_content_entries';

    /** Visible on the public site (published and not embargoed by future published_at). */
    private const PUBLIC_ENTRY_WHERE = "deleted_at IS NULL AND status = 'published' AND (published_at IS NULL OR published_at <= NOW(6))";

    /** Same semantics as {@see PUBLIC_ENTRY_WHERE} for queries that alias entries as `e`. */
    private const PUBLIC_ENTRY_WHERE_E = "e.deleted_at IS NULL AND e.status = 'published' AND (e.published_at IS NULL OR e.published_at <= NOW(6))";

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forTypeOrdered(int $contentTypeId, int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));
        $stmt = $this->pdo->prepare(
            'SELECT e.*, u.email AS author_email FROM ' . self::TABLE . ' e
             LEFT JOIN cms_users u ON u.id = e.created_by
             WHERE e.content_type_id = ? AND e.deleted_at IS NULL ORDER BY e.updated_at DESC, e.id DESC LIMIT ' . $limit
        );
        $stmt->execute([$contentTypeId]);
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[] = $row;
        }

        return $out;
    }

    public function countForContentType(int $contentTypeId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE content_type_id = ? AND deleted_at IS NULL');
        $stmt->execute([$contentTypeId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Admin entry list for one content type (newest first).
     *
     * @return list<array<string, mixed>>
     */
    public function forTypeAdminPaged(int $contentTypeId, int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;
        $stmt = $this->pdo->prepare(
            'SELECT e.*, u.email AS author_email FROM ' . self::TABLE . ' e
             LEFT JOIN cms_users u ON u.id = e.created_by
             WHERE e.content_type_id = ? AND e.deleted_at IS NULL ORDER BY e.updated_at DESC, e.id DESC
             LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset
        );
        $stmt->execute([$contentTypeId]);
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[] = $row;
        }

        return $out;
    }

    public function findById(int $id): ?ContentEntry
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ' . self::TABLE . ' WHERE id = ? AND deleted_at IS NULL LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : ContentEntry::fromRow($row);
    }

    /**
     * @return ?array<string, mixed>
     */
    public function fetchRowById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ' . self::TABLE . ' WHERE id = ? AND deleted_at IS NULL LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * @param list<int> $ids
     * @return array<int, array<string, mixed>> keyed by entry id
     */
    public function fetchRowsByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map(static fn ($v): int => (int) $v, $ids), static fn (int $i): bool => $i > 0)));
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare('SELECT * FROM ' . self::TABLE . ' WHERE id IN (' . $placeholders . ') AND deleted_at IS NULL');
        $stmt->execute($ids);
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[(int) $row['id']] = $row;
        }

        return $out;
    }

    public function countPublishedForContentType(int $contentTypeId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE content_type_id = ? AND ' . self::PUBLIC_ENTRY_WHERE
        );
        $stmt->execute([$contentTypeId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function publishedForContentTypePaged(int $contentTypeId, int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(50, $perPage));
        $offset = ($page - 1) * $perPage;
        $sql = 'SELECT * FROM ' . self::TABLE
            . ' WHERE content_type_id = ? AND ' . self::PUBLIC_ENTRY_WHERE . ' ORDER BY published_at DESC, updated_at DESC LIMIT '
            . (int) $perPage . ' OFFSET ' . (int) $offset;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$contentTypeId]);
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Public (routed) content type that is not a store catalog slug and has at least one published entry,
     * preferring the type with the most recent activity.
     */
    public function firstPublicNonCatalogTypeIdWithPublishedEntries(): ?int
    {
        $exclude = ['products', 'store', 'catalog', 'shop'];
        $placeholders = implode(',', array_fill(0, count($exclude), '?'));
        $sql = 'SELECT t.id FROM cms_content_types t
            INNER JOIN cms_content_entries e ON e.content_type_id = t.id AND ' . self::PUBLIC_ENTRY_WHERE_E . '
            WHERE t.has_public_route = 1 AND LOWER(t.slug) NOT IN (' . $placeholders . ')
            GROUP BY t.id
            ORDER BY MAX(COALESCE(e.published_at, e.updated_at)) DESC
            LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_map(static fn (string $s): string => strtolower($s), $exclude));
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    public function findPublishedByTypeSlug(int $contentTypeId, string $slug): ?ContentEntry
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ' . self::TABLE
            . ' WHERE content_type_id = ? AND slug = ? AND ' . self::PUBLIC_ENTRY_WHERE . ' LIMIT 1'
        );
        $stmt->execute([$contentTypeId, $slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : ContentEntry::fromRow($row);
    }

    public function findByTypeAndSlug(int $contentTypeId, string $slug): ?ContentEntry
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ' . self::TABLE
            . ' WHERE content_type_id = ? AND slug = ? AND deleted_at IS NULL LIMIT 1'
        );
        $stmt->execute([$contentTypeId, $slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : ContentEntry::fromRow($row);
    }

    /**
     * Published entries for a type (admin tools, AI comments, etc.).
     *
     * @return list<array{id: int, title: string, slug: string}>
     */
    public function listPublishedSummariesForContentType(int $contentTypeId, int $limit = 500): array
    {
        if ($contentTypeId < 1) {
            return [];
        }
        $limit = max(1, min(500, $limit));
        $stmt = $this->pdo->prepare(
            'SELECT id, title, slug FROM ' . self::TABLE
            . ' WHERE content_type_id = ? AND ' . self::PUBLIC_ENTRY_WHERE . ' ORDER BY published_at DESC, updated_at DESC LIMIT ' . $limit
        );
        $stmt->execute([$contentTypeId]);
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!is_array($row)) {
                continue;
            }
            $out[] = [
                'id' => (int) ($row['id'] ?? 0),
                'title' => (string) ($row['title'] ?? ''),
                'slug' => (string) ($row['slug'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * @param list<string> $statuses
     */
    public function countForContentTypeWithStatuses(int $contentTypeId, array $statuses): int
    {
        if ($statuses === []) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($statuses), '?'));
        $sql = 'SELECT COUNT(*) FROM ' . self::TABLE
            . ' WHERE content_type_id = ? AND status IN (' . $placeholders . ') AND deleted_at IS NULL';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_merge([$contentTypeId], $statuses));

        return (int) $stmt->fetchColumn();
    }

    /**
     * Entry totals keyed by content type id (empty when no types exist).
     *
     * @return array<int, array{total: int, published: int, draft: int, in_review: int}>
     */
    public function statsByContentType(): array
    {
        $stmt = $this->pdo->query(
            'SELECT content_type_id,
                COUNT(*) AS total,
                SUM(CASE WHEN status = \'published\' THEN 1 ELSE 0 END) AS published,
                SUM(CASE WHEN status = \'draft\' THEN 1 ELSE 0 END) AS draft,
                SUM(CASE WHEN status = \'in_review\' THEN 1 ELSE 0 END) AS in_review
             FROM ' . self::TABLE . '
             WHERE deleted_at IS NULL
             GROUP BY content_type_id'
        );
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $id = (int) $row['content_type_id'];
            $out[$id] = [
                'total' => (int) $row['total'],
                'published' => (int) $row['published'],
                'draft' => (int) $row['draft'],
                'in_review' => (int) $row['in_review'],
            ];
        }

        return $out;
    }

    /**
     * @param list<string> $statuses
     * @return list<array<string, mixed>>
     */
    public function listForContentTypePagedWithStatuses(int $contentTypeId, array $statuses, int $page, int $perPage): array
    {
        if ($statuses === []) {
            return [];
        }
        $page = max(1, $page);
        $perPage = max(1, min(50, $perPage));
        $offset = ($page - 1) * $perPage;
        $placeholders = implode(',', array_fill(0, count($statuses), '?'));
        $sql = 'SELECT * FROM ' . self::TABLE
            . ' WHERE content_type_id = ? AND status IN (' . $placeholders . ') AND deleted_at IS NULL'
            . ' ORDER BY COALESCE(published_at, updated_at) DESC, id DESC LIMIT '
            . (int) $perPage . ' OFFSET ' . (int) $offset;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_merge([$contentTypeId], $statuses));
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[] = $row;
        }

        return $out;
    }

    public function slugExists(int $contentTypeId, string $slug, ?int $exceptEntryId = null): bool
    {
        if ($exceptEntryId === null) {
            $stmt = $this->pdo->prepare(
                'SELECT 1 FROM ' . self::TABLE . ' WHERE content_type_id = ? AND slug = ? AND deleted_at IS NULL LIMIT 1'
            );
            $stmt->execute([$contentTypeId, $slug]);
        } else {
            $stmt = $this->pdo->prepare(
                'SELECT 1 FROM ' . self::TABLE . ' WHERE content_type_id = ? AND slug = ? AND id != ? AND deleted_at IS NULL LIMIT 1'
            );
            $stmt->execute([$contentTypeId, $slug, $exceptEntryId]);
        }

        return (bool) $stmt->fetchColumn();
    }

    /**
     * @return list<array{type_slug: string, slug: string, updated_at: string}>
     */
    public function publishedForSitemap(): array
    {
        $sql = 'SELECT e.slug, e.updated_at, t.slug AS type_slug FROM ' . self::TABLE . ' e
                INNER JOIN cms_content_types t ON t.id = e.content_type_id
                WHERE ' . self::PUBLIC_ENTRY_WHERE_E . ' AND t.has_public_route = 1
                  AND COALESCE(e.seo_noindex, 0) = 0
                ORDER BY e.updated_at DESC';
        $stmt = $this->pdo->query($sql);
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[] = [
                'type_slug' => (string) $row['type_slug'],
                'slug' => (string) $row['slug'],
                'updated_at' => (string) ($row['updated_at'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    public function publishedSlugsForType(int $contentTypeId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT slug FROM ' . self::TABLE . ' WHERE content_type_id = ? AND ' . self::PUBLIC_ENTRY_WHERE
            . ' ORDER BY slug ASC'
        );
        $stmt->execute([$contentTypeId]);
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[] = (string) $row['slug'];
        }

        return $out;
    }

    /**
     * @return int new id
     */
    public function insert(
        int $contentTypeId,
        string $title,
        string $slug,
        string $status,
        ?int $featuredImageId,
        ?string $seoTitle,
        ?string $seoDescription,
        ?string $focusKeyphrase,
        ?string $canonicalUrl,
        bool $seoNoindex,
        ?string $ogTitle,
        ?string $ogDescription,
        ?int $ogImageId,
        ?string $twitterTitle,
        ?string $twitterDescription,
        ?int $twitterImageId,
        ?string $schemaJson,
        ?string $publishedAt,
        ?string $scheduledPublishAt,
        ?string $scheduledUnpublishAt,
        ?int $createdBy
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . self::TABLE . ' (
                content_type_id, title, slug, status, featured_image_id,
                seo_title, seo_description, focus_keyphrase, canonical_url, seo_noindex,
                og_title, og_description, og_image_id, twitter_title, twitter_description, twitter_image_id, schema_json,
                published_at, scheduled_publish_at, scheduled_unpublish_at, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $contentTypeId,
            $title,
            $slug,
            $status,
            $featuredImageId,
            $seoTitle,
            $seoDescription,
            $focusKeyphrase,
            $canonicalUrl,
            $seoNoindex ? 1 : 0,
            $ogTitle,
            $ogDescription,
            $ogImageId,
            $twitterTitle,
            $twitterDescription,
            $twitterImageId,
            $schemaJson,
            $publishedAt,
            $scheduledPublishAt,
            $scheduledUnpublishAt,
            $createdBy,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function update(
        int $id,
        string $title,
        string $slug,
        string $status,
        ?int $featuredImageId,
        ?string $seoTitle,
        ?string $seoDescription,
        ?string $focusKeyphrase,
        ?string $canonicalUrl,
        bool $seoNoindex,
        ?string $ogTitle,
        ?string $ogDescription,
        ?int $ogImageId,
        ?string $twitterTitle,
        ?string $twitterDescription,
        ?int $twitterImageId,
        ?string $schemaJson,
        ?string $publishedAt,
        ?string $scheduledPublishAt,
        ?string $scheduledUnpublishAt
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE ' . self::TABLE . ' SET title = ?, slug = ?, status = ?, featured_image_id = ?,
             seo_title = ?, seo_description = ?, focus_keyphrase = ?, canonical_url = ?, seo_noindex = ?,
             og_title = ?, og_description = ?, og_image_id = ?, twitter_title = ?, twitter_description = ?, twitter_image_id = ?, schema_json = ?,
             published_at = ?, scheduled_publish_at = ?, scheduled_unpublish_at = ? WHERE id = ?'
        );
        $stmt->execute([
            $title,
            $slug,
            $status,
            $featuredImageId,
            $seoTitle,
            $seoDescription,
            $focusKeyphrase,
            $canonicalUrl,
            $seoNoindex ? 1 : 0,
            $ogTitle,
            $ogDescription,
            $ogImageId,
            $twitterTitle,
            $twitterDescription,
            $twitterImageId,
            $schemaJson,
            $publishedAt,
            $scheduledPublishAt,
            $scheduledUnpublishAt,
            $id,
        ]);
    }

    public function trash(int $id, ?int $deletedBy = null): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE ' . self::TABLE . ' SET deleted_at = NOW(6), deleted_by = ? WHERE id = ? AND deleted_at IS NULL'
        );
        $stmt->execute([$deletedBy, $id]);

        return $stmt->rowCount() > 0;
    }

    public function restore(int $id): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE ' . self::TABLE . ' SET deleted_at = NULL, deleted_by = NULL WHERE id = ? AND deleted_at IS NOT NULL'
        );
        $stmt->execute([$id]);

        return $stmt->rowCount() > 0;
    }

    public function purge(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM ' . self::TABLE . ' WHERE id = ? AND deleted_at IS NOT NULL');
        $stmt->execute([$id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listTrashed(int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));
        $stmt = $this->pdo->prepare(
            'SELECT e.id, e.title, e.slug, e.content_type_id, e.deleted_at, t.name AS type_name
             FROM ' . self::TABLE . ' e
             INNER JOIN cms_content_types t ON t.id = e.content_type_id
             WHERE e.deleted_at IS NOT NULL
             ORDER BY e.deleted_at DESC
             LIMIT ' . $limit
        );
        $stmt->execute();
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[] = $row;
        }

        return $out;
    }

    public function countTrashed(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE deleted_at IS NOT NULL')->fetchColumn();
    }

    /** @deprecated Use trash() or purge() */
    public function delete(int $id): void
    {
        $this->purge($id);
    }

    public function belongsToType(int $entryId, int $contentTypeId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM ' . self::TABLE . ' WHERE id = ? AND content_type_id = ? AND deleted_at IS NULL LIMIT 1'
        );
        $stmt->execute([$entryId, $contentTypeId]);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * Published entries with a public URL, prioritizing the given content type (for AI internal-link context).
     *
     * @return list<array{path: string, title: string, type_name: string}>
     */
    public function listPublishedPublicPathsForSiteContext(int $prioritizeContentTypeId, int $limit): array
    {
        $limit = max(1, min(120, $limit));
        $sql = 'SELECT e.title, t.slug AS type_slug, e.slug AS entry_slug, t.name AS type_name
                FROM ' . self::TABLE . ' e
                INNER JOIN cms_content_types t ON t.id = e.content_type_id
                WHERE ' . self::PUBLIC_ENTRY_WHERE_E . ' AND t.has_public_route = 1
                  AND e.slug <> \'\' AND t.slug <> \'\'
                ORDER BY (e.content_type_id = ?) DESC,
                  COALESCE(e.published_at, e.updated_at) DESC,
                  e.id DESC
                LIMIT ' . $limit;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$prioritizeContentTypeId]);
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $typeSlug = (string) ($row['type_slug'] ?? '');
            $entrySlug = (string) ($row['entry_slug'] ?? '');
            if ($typeSlug === '' || $entrySlug === '') {
                continue;
            }
            $out[] = [
                'path' => '/' . $typeSlug . '/' . $entrySlug,
                'title' => (string) ($row['title'] ?? ''),
                'type_name' => (string) ($row['type_name'] ?? ''),
            ];
        }

        return $out;
    }
}
