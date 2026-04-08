<?php

declare(strict_types=1);

namespace App\Seo;

use PDO;

final class NotFoundLogRepository
{
    private const TABLE = 'cms_not_found_logs';

    private const EVENTS_TABLE = 'cms_not_found_hit_events';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function record(string $path, ?string $referer, string $clientIp, ?string $userAgent): void
    {
        $path = mb_substr($path, 0, 2000);
        $ref = $referer !== null && $referer !== '' ? mb_substr($referer, 0, 2000) : null;
        $clientIp = mb_substr($clientIp, 0, 45);
        $ua = $userAgent !== null && $userAgent !== '' ? mb_substr($userAgent, 0, 512) : null;
        $sql = 'INSERT INTO ' . self::TABLE . ' (path, referer, hit_count, last_seen_at)
                VALUES (?, ?, 1, CURRENT_TIMESTAMP)
                ON DUPLICATE KEY UPDATE
                  hit_count = hit_count + 1,
                  last_seen_at = CURRENT_TIMESTAMP,
                  referer = COALESCE(VALUES(referer), referer)';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$path, $ref]);

        $idStmt = $this->pdo->prepare('SELECT id FROM ' . self::TABLE . ' WHERE path = ? LIMIT 1');
        $idStmt->execute([$path]);
        $logId = (int) $idStmt->fetchColumn();
        if ($logId < 1) {
            return;
        }

        try {
            $ev = $this->pdo->prepare(
                'INSERT INTO ' . self::EVENTS_TABLE . ' (log_id, client_ip, user_agent) VALUES (?, ?, ?)'
            );
            $ev->execute([$logId, $clientIp, $ua]);
        } catch (\Throwable) {
            // Deploy window before migration 026, or missing table — aggregate log still works.
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ' . self::TABLE . ' WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listHitsForLogId(int $logId, int $limit = 300): array
    {
        $logId = max(1, $logId);
        $limit = max(1, min(500, $limit));
        $stmt = $this->pdo->prepare(
            'SELECT id, client_ip, user_agent, seen_at FROM ' . self::EVENTS_TABLE
            . ' WHERE log_id = :lid ORDER BY seen_at DESC LIMIT :lim'
        );
        $stmt->bindValue(':lid', $logId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[] = $row;
        }

        return $out;
    }

    public function countAll(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM ' . self::TABLE)->fetchColumn();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listByHitsPage(int $limit, int $offset): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ' . self::TABLE . ' ORDER BY hit_count DESC, last_seen_at DESC LIMIT :lim OFFSET :off'
        );
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[] = $row;
        }

        return $out;
    }

    public function deleteById(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM ' . self::TABLE . ' WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function deleteByPath(string $path): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM ' . self::TABLE . ' WHERE path = ?');
        $stmt->execute([$path]);
    }
}
