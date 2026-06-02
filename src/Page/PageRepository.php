<?php

declare(strict_types=1);

namespace App\Page;

use PDO;

final class PageRepository
{
    private const TABLE = 'cms_pages';

    private const SELECT = 'id, title, slug, seo_title, seo_description, focus_keyphrase, tags_json, featured_image_id, canonical_url, seo_noindex, og_title, og_description, og_image_id, twitter_title, twitter_description, twitter_image_id, schema_json, content, status, published_at, scheduled_publish_at, scheduled_unpublish_at, comments_disabled, created_at, updated_at, deleted_at, deleted_by';

    private const NOT_TRASHED = 'deleted_at IS NULL';

    private const PUBLIC_WHERE = "deleted_at IS NULL AND status = 'published' AND (published_at IS NULL OR published_at <= NOW(6))";

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return list<Page>
     */
    public function allOrderedByUpdated(): array
    {
        $sql = 'SELECT ' . self::SELECT . ' FROM ' . self::TABLE . ' WHERE ' . self::NOT_TRASHED . ' ORDER BY updated_at DESC';
        $stmt = $this->pdo->query($sql);
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[] = Page::fromRow($row);
        }

        return $out;
    }

    public function findById(int $id): ?Page
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::SELECT . ' FROM ' . self::TABLE . ' WHERE id = ? AND ' . self::NOT_TRASHED . ' LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : Page::fromRow($row);
    }

    public function findPublishedBySlug(string $slug): ?Page
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::SELECT . ' FROM ' . self::TABLE
            . ' WHERE slug = ? AND ' . self::PUBLIC_WHERE . ' LIMIT 1'
        );
        $stmt->execute([$slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : Page::fromRow($row);
    }

    public function findBySlug(string $slug): ?Page
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::SELECT . ' FROM ' . self::TABLE
            . ' WHERE slug = ? AND ' . self::NOT_TRASHED . ' LIMIT 1'
        );
        $stmt->execute([$slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : Page::fromRow($row);
    }

    public function findPublishedSlugById(int $id): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT slug FROM ' . self::TABLE . ' WHERE id = ? AND ' . self::PUBLIC_WHERE . ' LIMIT 1'
        );
        $stmt->execute([$id]);
        $slug = $stmt->fetchColumn();

        return $slug === false ? null : (string) $slug;
    }

    /**
     * @return list<array{id: int, title: string, slug: string, updated_at: string}>
     */
    public function publishedForSitemap(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, title, slug, updated_at FROM ' . self::TABLE
            . ' WHERE ' . self::PUBLIC_WHERE . ' AND COALESCE(seo_noindex, 0) = 0 ORDER BY updated_at DESC'
        );
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[] = [
                'id' => (int) $row['id'],
                'title' => (string) $row['title'],
                'slug' => (string) $row['slug'],
                'updated_at' => (string) ($row['updated_at'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * @return list<array{id: int, title: string}>
     */
    public function idTitlePairsAll(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, title FROM ' . self::TABLE . ' WHERE ' . self::NOT_TRASHED . ' ORDER BY title ASC'
        );
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[] = ['id' => (int) $row['id'], 'title' => (string) $row['title']];
        }

        return $out;
    }

    /**
     * Published pages only (e.g. public homepage picker in Site settings).
     *
     * @return list<array{id: int, title: string}>
     */
    public function publishedIdTitlePairs(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, title FROM ' . self::TABLE . ' WHERE ' . self::PUBLIC_WHERE . ' ORDER BY title ASC'
        );
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[] = ['id' => (int) $row['id'], 'title' => (string) $row['title']];
        }

        return $out;
    }

    /**
     * Published CMS pages with public path `/p/{slug}` (for AI internal-link context).
     *
     * @return list<array{path: string, title: string}>
     */
    public function listPublishedPathsForSiteContext(int $limit): array
    {
        $limit = max(1, min(80, $limit));
        $stmt = $this->pdo->query(
            'SELECT title, slug FROM ' . self::TABLE
            . ' WHERE ' . self::PUBLIC_WHERE . " AND slug <> '' ORDER BY updated_at DESC LIMIT " . $limit
        );
        if ($stmt === false) {
            return [];
        }
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $slug = (string) ($row['slug'] ?? '');
            if ($slug === '') {
                continue;
            }
            $out[] = [
                'path' => '/p/' . $slug,
                'title' => (string) ($row['title'] ?? ''),
            ];
        }

        return $out;
    }

    public function slugExists(string $slug, ?int $exceptId = null): bool
    {
        if ($exceptId === null) {
            $stmt = $this->pdo->prepare('SELECT 1 FROM ' . self::TABLE . ' WHERE slug = ? AND ' . self::NOT_TRASHED . ' LIMIT 1');
            $stmt->execute([$slug]);
        } else {
            $stmt = $this->pdo->prepare('SELECT 1 FROM ' . self::TABLE . ' WHERE slug = ? AND id != ? AND ' . self::NOT_TRASHED . ' LIMIT 1');
            $stmt->execute([$slug, $exceptId]);
        }

        return (bool) $stmt->fetchColumn();
    }

    /**
     * @return int new id
     */
    public function insert(
        string $title,
        string $slug,
        ?string $seoTitle,
        ?string $seoDescription,
        ?string $focusKeyphrase,
        ?string $tagsJson,
        ?int $featuredImageId,
        ?string $canonicalUrl,
        bool $seoNoindex,
        ?string $ogTitle,
        ?string $ogDescription,
        ?int $ogImageId,
        ?string $twitterTitle,
        ?string $twitterDescription,
        ?int $twitterImageId,
        ?string $schemaJson,
        string $content,
        string $status,
        ?string $publishedAt,
        ?string $scheduledPublishAt,
        ?string $scheduledUnpublishAt,
        bool $commentsDisabled = false,
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . self::TABLE . ' (
                title, slug, seo_title, seo_description, focus_keyphrase, tags_json, featured_image_id,
                canonical_url, seo_noindex, og_title, og_description, og_image_id,
                twitter_title, twitter_description, twitter_image_id, schema_json,
                content, status, published_at, scheduled_publish_at, scheduled_unpublish_at, comments_disabled
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $title,
            $slug,
            $seoTitle,
            $seoDescription,
            $focusKeyphrase,
            $tagsJson,
            $featuredImageId,
            $canonicalUrl,
            $seoNoindex ? 1 : 0,
            $ogTitle,
            $ogDescription,
            $ogImageId,
            $twitterTitle,
            $twitterDescription,
            $twitterImageId,
            $schemaJson,
            $content,
            $status,
            $publishedAt,
            $scheduledPublishAt,
            $scheduledUnpublishAt,
            $commentsDisabled ? 1 : 0,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function update(
        int $id,
        string $title,
        string $slug,
        ?string $seoTitle,
        ?string $seoDescription,
        ?string $focusKeyphrase,
        ?string $tagsJson,
        ?int $featuredImageId,
        ?string $canonicalUrl,
        bool $seoNoindex,
        ?string $ogTitle,
        ?string $ogDescription,
        ?int $ogImageId,
        ?string $twitterTitle,
        ?string $twitterDescription,
        ?int $twitterImageId,
        ?string $schemaJson,
        string $content,
        string $status,
        ?string $publishedAt,
        ?string $scheduledPublishAt,
        ?string $scheduledUnpublishAt,
        ?int $updatedBy = null,
        bool $commentsDisabled = false,
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE ' . self::TABLE . ' SET title = ?, slug = ?, seo_title = ?, seo_description = ?, focus_keyphrase = ?, tags_json = ?, featured_image_id = ?,
             canonical_url = ?, seo_noindex = ?, og_title = ?, og_description = ?, og_image_id = ?,
             twitter_title = ?, twitter_description = ?, twitter_image_id = ?, schema_json = ?,
             content = ?, status = ?, published_at = ?, scheduled_publish_at = ?, scheduled_unpublish_at = ?, comments_disabled = ?, updated_by = ? WHERE id = ?'
        );
        $stmt->execute([
            $title,
            $slug,
            $seoTitle,
            $seoDescription,
            $focusKeyphrase,
            $tagsJson,
            $featuredImageId,
            $canonicalUrl,
            $seoNoindex ? 1 : 0,
            $ogTitle,
            $ogDescription,
            $ogImageId,
            $twitterTitle,
            $twitterDescription,
            $twitterImageId,
            $schemaJson,
            $content,
            $status,
            $publishedAt,
            $scheduledPublishAt,
            $scheduledUnpublishAt,
            $commentsDisabled ? 1 : 0,
            $updatedBy,
            $id,
        ]);
    }

    public function trash(int $id, ?int $deletedBy = null): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE ' . self::TABLE . ' SET deleted_at = NOW(6), deleted_by = ? WHERE id = ? AND ' . self::NOT_TRASHED
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
            'SELECT id, title, slug, status, deleted_at, deleted_by FROM ' . self::TABLE
            . ' WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC LIMIT ' . $limit
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
}
