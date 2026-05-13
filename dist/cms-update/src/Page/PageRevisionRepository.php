<?php

declare(strict_types=1);

namespace App\Page;

use PDO;

final class PageRevisionRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function captureFromPage(Page $page, ?int $createdBy): void
    {
        $tagsJson = PageTagParser::toJson($page->tags);
        $stmt = $this->pdo->prepare(
            'INSERT INTO cms_page_revisions (
                page_id, title, slug, seo_title, seo_description, tags_json, featured_image_id,
                canonical_url, seo_noindex, og_title, og_description, og_image_id,
                twitter_title, twitter_description, twitter_image_id, schema_json,
                content, status, published_at, scheduled_publish_at, scheduled_unpublish_at, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $page->id,
            $page->title,
            $page->slug,
            $page->seoTitle,
            $page->seoDescription,
            $tagsJson,
            $page->featuredImageId,
            $page->canonicalUrl,
            $page->seoNoindex ? 1 : 0,
            $page->ogTitle,
            $page->ogDescription,
            $page->ogImageId,
            $page->twitterTitle,
            $page->twitterDescription,
            $page->twitterImageId,
            $page->schemaJson,
            $page->content,
            $page->status,
            $page->publishedAt,
            $page->scheduledPublishAt,
            $page->scheduledUnpublishAt,
            $createdBy,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForPage(int $pageId, int $limit = 50): array
    {
        $limit = max(1, min(100, $limit));
        $stmt = $this->pdo->prepare(
            'SELECT r.*, u.email AS author_email, u.display_name AS author_name
             FROM cms_page_revisions r
             LEFT JOIN cms_users u ON u.id = r.created_by
             WHERE r.page_id = ?
             ORDER BY r.created_at DESC
             LIMIT ' . (int) $limit
        );
        $stmt->execute([$pageId]);
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[] = $row;
        }

        return $out;
    }

    /**
     * @return array{rows: list<array<string, mixed>>, has_more: bool}
     */
    public function listPreviewForSidebar(int $pageId, int $show = 3, int $extraFetch = 1): array
    {
        $show = max(1, min(10, $show));
        $extra = max(0, min(5, $extraFetch));
        $limit = $show + $extra;
        $rows = $this->listForPage($pageId, $limit);
        $hasMore = count($rows) > $show;

        return [
            'rows' => array_slice($rows, 0, $show),
            'has_more' => $hasMore,
        ];
    }

    public function findById(int $revisionId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM cms_page_revisions WHERE id = ? LIMIT 1');
        $stmt->execute([$revisionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }
}
