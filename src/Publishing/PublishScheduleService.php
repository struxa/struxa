<?php

declare(strict_types=1);

namespace App\Publishing;

use App\Event\ContentEntrySavedEvent;
use App\Event\Events;
use App\Event\StorefrontCachesInvalidateEvent;
use PDO;
use Throwable;

/**
 * Applies due scheduled publish/unpublish for pages and content entries.
 * Safe to run concurrently (idempotent row updates); may double-fire events in rare races.
 */
final class PublishScheduleService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array{
     *   published_entries: int,
     *   unpublished_entries: int,
     *   published_pages: int,
     *   unpublished_pages: int,
     *   errors: list<string>
     * }
     */
    public function runDue(): array
    {
        $out = [
            'published_entries' => 0,
            'unpublished_entries' => 0,
            'published_pages' => 0,
            'unpublished_pages' => 0,
            'errors' => [],
        ];

        try {
            $out['published_entries'] = $this->publishDueContentEntries();
        } catch (Throwable $e) {
            $out['errors'][] = 'content_entries publish: ' . $e->getMessage();
        }
        try {
            $out['unpublished_entries'] = $this->unpublishDueContentEntries();
        } catch (Throwable $e) {
            $out['errors'][] = 'content_entries unpublish: ' . $e->getMessage();
        }
        try {
            $out['published_pages'] = $this->publishDuePages();
        } catch (Throwable $e) {
            $out['errors'][] = 'pages publish: ' . $e->getMessage();
        }
        try {
            $out['unpublished_pages'] = $this->unpublishDuePages();
        } catch (Throwable $e) {
            $out['errors'][] = 'pages unpublish: ' . $e->getMessage();
        }

        return $out;
    }

    private function publishDueContentEntries(): int
    {
        $stmt = $this->pdo->query(
            "SELECT id, content_type_id FROM cms_content_entries
             WHERE scheduled_publish_at IS NOT NULL
               AND scheduled_publish_at <= NOW(6)
               AND deleted_at IS NULL
               AND status IN ('draft','in_review','approved')
             LIMIT 100"
        );
        if ($stmt === false) {
            return 0;
        }
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $n = 0;
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $typeId = (int) ($row['content_type_id'] ?? 0);
            if ($id < 1 || $typeId < 1) {
                continue;
            }
            $u = $this->pdo->prepare(
                'UPDATE cms_content_entries SET
                    status = ?,
                    published_at = COALESCE(published_at, scheduled_publish_at),
                    scheduled_publish_at = NULL
                 WHERE id = ? AND scheduled_publish_at IS NOT NULL AND scheduled_publish_at <= NOW(6)
                   AND status IN (\'draft\',\'in_review\',\'approved\')'
            );
            $u->execute(['published', $id]);
            if ($u->rowCount() > 0) {
                $n++;
                Events::dispatch(new ContentEntrySavedEvent($id, $typeId, false));
            }
        }

        return $n;
    }

    private function unpublishDueContentEntries(): int
    {
        $stmt = $this->pdo->query(
            "SELECT id, content_type_id FROM cms_content_entries
             WHERE scheduled_unpublish_at IS NOT NULL
               AND scheduled_unpublish_at <= NOW(6)
               AND deleted_at IS NULL
               AND status = 'published'
             LIMIT 100"
        );
        if ($stmt === false) {
            return 0;
        }
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $n = 0;
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $typeId = (int) ($row['content_type_id'] ?? 0);
            if ($id < 1 || $typeId < 1) {
                continue;
            }
            $u = $this->pdo->prepare(
                'UPDATE cms_content_entries SET
                    status = ?,
                    scheduled_unpublish_at = NULL
                 WHERE id = ? AND scheduled_unpublish_at IS NOT NULL AND scheduled_unpublish_at <= NOW(6)
                   AND status = \'published\''
            );
            $u->execute(['draft', $id]);
            if ($u->rowCount() > 0) {
                $n++;
                Events::dispatch(new ContentEntrySavedEvent($id, $typeId, false));
            }
        }

        return $n;
    }

    private function publishDuePages(): int
    {
        $stmt = $this->pdo->query(
            "SELECT id FROM cms_pages
             WHERE scheduled_publish_at IS NOT NULL
               AND scheduled_publish_at <= NOW(6)
               AND deleted_at IS NULL
               AND status IN ('draft','in_review','approved')
             LIMIT 100"
        );
        if ($stmt === false) {
            return 0;
        }
        $ids = array_map(static fn (array $r): int => (int) ($r['id'] ?? 0), $stmt->fetchAll(PDO::FETCH_ASSOC));
        $n = 0;
        foreach ($ids as $id) {
            if ($id < 1) {
                continue;
            }
            $u = $this->pdo->prepare(
                'UPDATE cms_pages SET
                    status = ?,
                    published_at = COALESCE(published_at, scheduled_publish_at),
                    scheduled_publish_at = NULL
                 WHERE id = ? AND scheduled_publish_at IS NOT NULL AND scheduled_publish_at <= NOW(6)
                   AND status IN (\'draft\',\'in_review\',\'approved\')'
            );
            $u->execute(['published', $id]);
            if ($u->rowCount() > 0) {
                $n++;
                Events::dispatch(new StorefrontCachesInvalidateEvent('page_scheduled_publish'));
            }
        }

        return $n;
    }

    private function unpublishDuePages(): int
    {
        $stmt = $this->pdo->query(
            "SELECT id FROM cms_pages
             WHERE scheduled_unpublish_at IS NOT NULL
               AND scheduled_unpublish_at <= NOW(6)
               AND deleted_at IS NULL
               AND status = 'published'
             LIMIT 100"
        );
        if ($stmt === false) {
            return 0;
        }
        $ids = array_map(static fn (array $r): int => (int) ($r['id'] ?? 0), $stmt->fetchAll(PDO::FETCH_ASSOC));
        $n = 0;
        foreach ($ids as $id) {
            if ($id < 1) {
                continue;
            }
            $u = $this->pdo->prepare(
                'UPDATE cms_pages SET
                    status = ?,
                    scheduled_unpublish_at = NULL
                 WHERE id = ? AND scheduled_unpublish_at IS NOT NULL AND scheduled_unpublish_at <= NOW(6)
                   AND status = \'published\''
            );
            $u->execute(['draft', $id]);
            if ($u->rowCount() > 0) {
                $n++;
                Events::dispatch(new StorefrontCachesInvalidateEvent('page_scheduled_unpublish'));
            }
        }

        return $n;
    }
}
