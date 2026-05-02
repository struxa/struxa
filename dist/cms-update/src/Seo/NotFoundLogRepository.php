<?php

declare(strict_types=1);

namespace App\Seo;

use PDO;

final class NotFoundLogRepository
{
    private const TABLE = 'cms_not_found_logs';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function record(string $path, ?string $referer): void
    {
        $path = mb_substr($path, 0, 2000);
        $ref = $referer !== null && $referer !== '' ? mb_substr($referer, 0, 2000) : null;
        $sql = 'INSERT INTO ' . self::TABLE . ' (path, referer, hit_count, last_seen_at)
                VALUES (?, ?, 1, CURRENT_TIMESTAMP)
                ON DUPLICATE KEY UPDATE
                  hit_count = hit_count + 1,
                  last_seen_at = CURRENT_TIMESTAMP,
                  referer = COALESCE(VALUES(referer), referer)';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$path, $ref]);
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
