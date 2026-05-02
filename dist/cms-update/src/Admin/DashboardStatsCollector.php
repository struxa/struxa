<?php

declare(strict_types=1);

namespace App\Admin;

use App\Access\ActivityLogRepository;
use PDO;
use Throwable;

/**
 * Aggregates dashboard counts, recents, and workflow tallies (defensive against schema drift).
 */
final class DashboardStatsCollector
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array{
     *   pages: int,
     *   pages_published: int,
     *   pages_draft: int,
     *   entries: int,
     *   entries_published: int,
     *   entries_draft: int,
     *   entries_in_review: int,
     *   content_types: int,
     *   media: int,
     *   active_plugins: int,
     *   menus: int,
     *   recent_activity: list<array<string, mixed>>,
     *   recent_entries: list<array<string, mixed>>,
     *   entries_by_type: list<array{name: string, slug: string, count: int}>
     * }
     */
    public function collect(): array
    {
        $out = [
            'pages' => 0,
            'pages_published' => 0,
            'pages_draft' => 0,
            'entries' => 0,
            'entries_published' => 0,
            'entries_draft' => 0,
            'entries_in_review' => 0,
            'content_types' => 0,
            'media' => 0,
            'active_plugins' => 0,
            'menus' => 0,
            'recent_activity' => [],
            'recent_entries' => [],
            'entries_by_type' => [],
        ];

        try {
            $out['pages'] = (int) $this->pdo->query('SELECT COUNT(*) FROM cms_pages')->fetchColumn();
            $out['pages_published'] = (int) $this->pdo->query(
                "SELECT COUNT(*) FROM cms_pages WHERE status = 'published'"
            )->fetchColumn();
            $out['pages_draft'] = (int) $this->pdo->query(
                "SELECT COUNT(*) FROM cms_pages WHERE status = 'draft'"
            )->fetchColumn();
        } catch (Throwable) {
        }

        try {
            $out['entries'] = (int) $this->pdo->query('SELECT COUNT(*) FROM cms_content_entries')->fetchColumn();
            $out['entries_published'] = (int) $this->pdo->query(
                "SELECT COUNT(*) FROM cms_content_entries WHERE status = 'published'"
            )->fetchColumn();
        } catch (Throwable) {
        }

        try {
            $out['entries_draft'] = (int) $this->pdo->query(
                "SELECT COUNT(*) FROM cms_content_entries WHERE status = 'draft'"
            )->fetchColumn();
        } catch (Throwable) {
        }

        try {
            $out['entries_in_review'] = (int) $this->pdo->query(
                "SELECT COUNT(*) FROM cms_content_entries WHERE status = 'in_review'"
            )->fetchColumn();
        } catch (Throwable) {
        }

        try {
            $out['content_types'] = (int) $this->pdo->query('SELECT COUNT(*) FROM cms_content_types')->fetchColumn();
        } catch (Throwable) {
        }

        try {
            $out['media'] = (int) $this->pdo->query('SELECT COUNT(*) FROM cms_media')->fetchColumn();
        } catch (Throwable) {
        }

        try {
            $out['active_plugins'] = (int) $this->pdo->query(
                'SELECT COUNT(*) FROM cms_plugins WHERE is_active = 1'
            )->fetchColumn();
        } catch (Throwable) {
        }

        try {
            $out['menus'] = (int) $this->pdo->query('SELECT COUNT(*) FROM cms_menus')->fetchColumn();
        } catch (Throwable) {
        }

        try {
            $out['recent_activity'] = (new ActivityLogRepository($this->pdo))->recent(12);
        } catch (Throwable) {
        }

        try {
            $sql = 'SELECT e.id, e.title, e.slug, e.status, e.updated_at, t.id AS type_id, t.name AS type_name, t.slug AS type_slug
                    FROM cms_content_entries e
                    INNER JOIN cms_content_types t ON t.id = e.content_type_id
                    ORDER BY e.updated_at DESC
                    LIMIT 8';
            $stmt = $this->pdo->query($sql);
            if ($stmt !== false) {
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $out['recent_entries'][] = $row;
                }
            }
        } catch (Throwable) {
        }

        try {
            $sql = 'SELECT t.name, t.slug, COUNT(e.id) AS cnt
                    FROM cms_content_types t
                    LEFT JOIN cms_content_entries e ON e.content_type_id = t.id
                    GROUP BY t.id, t.name, t.slug
                    ORDER BY t.name ASC';
            $stmt = $this->pdo->query($sql);
            if ($stmt !== false) {
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $out['entries_by_type'][] = [
                        'name' => (string) $row['name'],
                        'slug' => (string) $row['slug'],
                        'count' => (int) $row['cnt'],
                    ];
                }
            }
        } catch (Throwable) {
        }

        return $out;
    }
}
