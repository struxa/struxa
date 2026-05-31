<?php

declare(strict_types=1);

namespace App\Seo;

use PDO;

/**
 * Site-wide SEO health summary for the admin overview dashboard.
 */
final class SeoOverviewService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array{
     *   pages_total: int,
     *   pages_published: int,
     *   pages_missing_description: int,
     *   pages_missing_keyphrase: int,
     *   pages_noindex: int,
     *   entries_total: int,
     *   entries_published: int,
     *   entries_missing_description: int,
     *   entries_missing_keyphrase: int,
     *   entries_noindex: int,
     *   recent_pages: list<array{id: int, title: string, slug: string, issue: string}>,
     *   recent_entries: list<array{id: int, title: string, slug: string, type_name: string, type_id: int, issue: string}>
     * }
     */
    public function summary(): array
    {
        $pagesTotal = (int) $this->pdo->query('SELECT COUNT(*) FROM cms_pages WHERE deleted_at IS NULL')->fetchColumn();
        $pagesPublished = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM cms_pages WHERE deleted_at IS NULL AND status = 'published' AND (published_at IS NULL OR published_at <= NOW(6))"
        )->fetchColumn();
        $pagesMissingDesc = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM cms_pages WHERE deleted_at IS NULL AND status = 'published' AND (published_at IS NULL OR published_at <= NOW(6))
             AND (seo_description IS NULL OR TRIM(seo_description) = '')"
        )->fetchColumn();
        $pagesMissingKp = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM cms_pages WHERE deleted_at IS NULL AND status = 'published' AND (published_at IS NULL OR published_at <= NOW(6))
             AND (focus_keyphrase IS NULL OR TRIM(focus_keyphrase) = '')"
        )->fetchColumn();
        $pagesNoindex = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM cms_pages WHERE deleted_at IS NULL AND status = 'published' AND seo_noindex = 1"
        )->fetchColumn();

        $entriesTotal = (int) $this->pdo->query('SELECT COUNT(*) FROM cms_content_entries WHERE deleted_at IS NULL')->fetchColumn();
        $entriesPublished = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM cms_content_entries WHERE deleted_at IS NULL AND status = 'published' AND (published_at IS NULL OR published_at <= NOW(6))"
        )->fetchColumn();
        $entriesMissingDesc = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM cms_content_entries WHERE deleted_at IS NULL AND status = 'published' AND (published_at IS NULL OR published_at <= NOW(6))
             AND (seo_description IS NULL OR TRIM(seo_description) = '')"
        )->fetchColumn();
        $entriesMissingKp = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM cms_content_entries WHERE deleted_at IS NULL AND status = 'published' AND (published_at IS NULL OR published_at <= NOW(6))
             AND (focus_keyphrase IS NULL OR TRIM(focus_keyphrase) = '')"
        )->fetchColumn();
        $entriesNoindex = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM cms_content_entries WHERE deleted_at IS NULL AND status = 'published' AND seo_noindex = 1"
        )->fetchColumn();

        $recentPages = [];
        $stmt = $this->pdo->query(
            "SELECT id, title, slug, seo_description, focus_keyphrase FROM cms_pages
             WHERE deleted_at IS NULL AND status = 'published' AND (published_at IS NULL OR published_at <= NOW(6))
             AND ((seo_description IS NULL OR TRIM(seo_description) = '') OR (focus_keyphrase IS NULL OR TRIM(focus_keyphrase) = ''))
             ORDER BY updated_at DESC LIMIT 8"
        );
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $issues = [];
            if (trim((string) ($row['seo_description'] ?? '')) === '') {
                $issues[] = 'missing meta description';
            }
            if (trim((string) ($row['focus_keyphrase'] ?? '')) === '') {
                $issues[] = 'no focus keyphrase';
            }
            $recentPages[] = [
                'id' => (int) $row['id'],
                'title' => (string) $row['title'],
                'slug' => (string) $row['slug'],
                'issue' => implode('; ', $issues),
            ];
        }

        $recentEntries = [];
        $stmt = $this->pdo->query(
            "SELECT e.id, e.title, e.slug, e.seo_description, e.focus_keyphrase, t.name AS type_name, t.id AS type_id
             FROM cms_content_entries e
             INNER JOIN cms_content_types t ON t.id = e.content_type_id
             WHERE e.deleted_at IS NULL AND e.status = 'published' AND (e.published_at IS NULL OR e.published_at <= NOW(6))
             AND ((e.seo_description IS NULL OR TRIM(e.seo_description) = '') OR (e.focus_keyphrase IS NULL OR TRIM(e.focus_keyphrase) = ''))
             ORDER BY e.updated_at DESC LIMIT 8"
        );
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $issues = [];
            if (trim((string) ($row['seo_description'] ?? '')) === '') {
                $issues[] = 'missing meta description';
            }
            if (trim((string) ($row['focus_keyphrase'] ?? '')) === '') {
                $issues[] = 'no focus keyphrase';
            }
            $recentEntries[] = [
                'id' => (int) $row['id'],
                'title' => (string) $row['title'],
                'slug' => (string) $row['slug'],
                'type_name' => (string) $row['type_name'],
                'type_id' => (int) $row['type_id'],
                'issue' => implode('; ', $issues),
            ];
        }

        return [
            'pages_total' => $pagesTotal,
            'pages_published' => $pagesPublished,
            'pages_missing_description' => $pagesMissingDesc,
            'pages_missing_keyphrase' => $pagesMissingKp,
            'pages_noindex' => $pagesNoindex,
            'entries_total' => $entriesTotal,
            'entries_published' => $entriesPublished,
            'entries_missing_description' => $entriesMissingDesc,
            'entries_missing_keyphrase' => $entriesMissingKp,
            'entries_noindex' => $entriesNoindex,
            'recent_pages' => $recentPages,
            'recent_entries' => $recentEntries,
        ];
    }
}
