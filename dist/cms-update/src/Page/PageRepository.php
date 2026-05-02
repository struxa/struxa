<?php

declare(strict_types=1);

namespace App\Page;

use PDO;

final class PageRepository
{
    private const TABLE = 'cms_pages';

    private const SELECT = 'id, title, slug, seo_title, seo_description, tags_json, featured_image_id, canonical_url, seo_noindex, og_title, og_description, og_image_id, twitter_title, twitter_description, twitter_image_id, schema_json, content, status, created_at, updated_at';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return list<Page>
     */
    public function allOrderedByUpdated(): array
    {
        $sql = 'SELECT ' . self::SELECT . ' FROM ' . self::TABLE . ' ORDER BY updated_at DESC';
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
            'SELECT ' . self::SELECT . ' FROM ' . self::TABLE . ' WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : Page::fromRow($row);
    }

    public function findPublishedBySlug(string $slug): ?Page
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::SELECT . ' FROM ' . self::TABLE
            . ' WHERE slug = ? AND status = ? LIMIT 1'
        );
        $stmt->execute([$slug, 'published']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : Page::fromRow($row);
    }

    public function findBySlug(string $slug): ?Page
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::SELECT . ' FROM ' . self::TABLE
            . ' WHERE slug = ? LIMIT 1'
        );
        $stmt->execute([$slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : Page::fromRow($row);
    }

    public function findPublishedSlugById(int $id): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT slug FROM ' . self::TABLE . ' WHERE id = ? AND status = ? LIMIT 1'
        );
        $stmt->execute([$id, 'published']);
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
            . " WHERE status = 'published' AND COALESCE(seo_noindex, 0) = 0 ORDER BY updated_at DESC"
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
            'SELECT id, title FROM ' . self::TABLE . ' ORDER BY title ASC'
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
            'SELECT id, title FROM ' . self::TABLE . " WHERE status = 'published' ORDER BY title ASC"
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
            . " WHERE status = 'published' AND slug <> '' ORDER BY updated_at DESC LIMIT " . $limit
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
            $stmt = $this->pdo->prepare('SELECT 1 FROM ' . self::TABLE . ' WHERE slug = ? LIMIT 1');
            $stmt->execute([$slug]);
        } else {
            $stmt = $this->pdo->prepare('SELECT 1 FROM ' . self::TABLE . ' WHERE slug = ? AND id != ? LIMIT 1');
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
        string $status
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . self::TABLE . ' (
                title, slug, seo_title, seo_description, tags_json, featured_image_id,
                canonical_url, seo_noindex, og_title, og_description, og_image_id,
                twitter_title, twitter_description, twitter_image_id, schema_json,
                content, status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $title,
            $slug,
            $seoTitle,
            $seoDescription,
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
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function update(
        int $id,
        string $title,
        string $slug,
        ?string $seoTitle,
        ?string $seoDescription,
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
        ?int $updatedBy = null
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE ' . self::TABLE . ' SET title = ?, slug = ?, seo_title = ?, seo_description = ?, tags_json = ?, featured_image_id = ?,
             canonical_url = ?, seo_noindex = ?, og_title = ?, og_description = ?, og_image_id = ?,
             twitter_title = ?, twitter_description = ?, twitter_image_id = ?, schema_json = ?,
             content = ?, status = ?, updated_by = ? WHERE id = ?'
        );
        $stmt->execute([
            $title,
            $slug,
            $seoTitle,
            $seoDescription,
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
            $updatedBy,
            $id,
        ]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM ' . self::TABLE . ' WHERE id = ?');
        $stmt->execute([$id]);
    }
}
