<?php

declare(strict_types=1);

namespace App\Security;

use PDO;
use PDOException;

final class IpBlockHitBucketRepository
{
    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    /**
     * @throws PDOException
     */
    public function addHits(string $clientIp, int $bucketHour, int $delta, string $path, string $userAgent): void
    {
        if ($delta < 1) {
            return;
        }
        $path = $this->truncatePath($path);
        $userAgent = $this->truncateUa($userAgent);

        $sql = 'INSERT INTO cms_ip_block_hit_buckets (client_ip, bucket_hour, hit_count, last_path, last_user_agent)
            VALUES (:ip, :bh, :delta, :p, :ua)
            ON DUPLICATE KEY UPDATE
                hit_count = hit_count + VALUES(hit_count),
                last_path = VALUES(last_path),
                last_user_agent = VALUES(last_user_agent),
                last_seen_at = CURRENT_TIMESTAMP';

        $st = $this->pdo->prepare($sql);
        $st->execute([
            ':ip' => $clientIp,
            ':bh' => $bucketHour,
            ':delta' => $delta,
            ':p' => $path !== '' ? $path : null,
            ':ua' => $userAgent !== '' ? $userAgent : null,
        ]);
    }

    /**
     * Recent aggregated rows (one row per client IP per clock hour), newest activity first.
     *
     * @return list<array{id: int, client_ip: string, bucket_hour: int, hit_count: int, last_path: ?string, last_user_agent: ?string, last_seen_at: string}>
     */
    public function listRecent(int $limit, int $maxAgeDays = 30): array
    {
        $limit = max(1, min(500, $limit));
        $maxAgeDays = max(1, min(365, $maxAgeDays));

        try {
            $st = $this->pdo->prepare(
                'SELECT id, client_ip, bucket_hour, hit_count, last_path, last_user_agent, last_seen_at
                 FROM cms_ip_block_hit_buckets
                 WHERE last_seen_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
                 ORDER BY last_seen_at DESC
                 LIMIT ' . (int) $limit
            );
            $st->bindValue(':days', $maxAgeDays, PDO::PARAM_INT);
            $st->execute();
            $out = [];
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                if (!is_array($row)) {
                    continue;
                }
                $out[] = [
                    'id' => (int) ($row['id'] ?? 0),
                    'client_ip' => (string) ($row['client_ip'] ?? ''),
                    'bucket_hour' => (int) ($row['bucket_hour'] ?? 0),
                    'hit_count' => (int) ($row['hit_count'] ?? 0),
                    'last_path' => $this->nullableNonEmptyString($row['last_path'] ?? null),
                    'last_user_agent' => $this->nullableNonEmptyString($row['last_user_agent'] ?? null),
                    'last_seen_at' => (string) ($row['last_seen_at'] ?? ''),
                ];
            }

            return $out;
        } catch (PDOException) {
            return [];
        }
    }

    public function deleteOlderThanDays(int $days): int
    {
        $days = max(1, min(3650, $days));
        try {
            $st = $this->pdo->prepare(
                'DELETE FROM cms_ip_block_hit_buckets WHERE last_seen_at < DATE_SUB(NOW(), INTERVAL :days DAY)'
            );
            $st->bindValue(':days', $days, PDO::PARAM_INT);
            $st->execute();

            return $st->rowCount();
        } catch (PDOException) {
            return 0;
        }
    }

    private function nullableNonEmptyString(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string) $v);

        return $s === '' ? null : $s;
    }

    private function truncatePath(string $path): string
    {
        if ($path === '') {
            return '';
        }
        if (function_exists('mb_strlen') && function_exists('mb_substr') && mb_strlen($path, 'UTF-8') > 512) {
            return mb_substr($path, 0, 512, 'UTF-8');
        }

        return strlen($path) > 512 ? substr($path, 0, 512) : $path;
    }

    private function truncateUa(string $ua): string
    {
        if ($ua === '') {
            return '';
        }
        if (function_exists('mb_strlen') && function_exists('mb_substr') && mb_strlen($ua, 'UTF-8') > 255) {
            return mb_substr($ua, 0, 255, 'UTF-8');
        }

        return strlen($ua) > 255 ? substr($ua, 0, 255) : $ua;
    }
}
