<?php

declare(strict_types=1);

namespace App\Analytics;

use PDO;

/**
 * Read/write access to {@code cms_external_link_clicks}. Aggregates are computed on demand via SQL
 * group-bys (indexed on destination_url_hash, destination_host, source_path and clicked_at).
 *
 * @phpstan-type ClickRow array{
 *   id: int,
 *   destination_url: string,
 *   destination_host: string,
 *   source_path: string,
 *   source_url: ?string,
 *   referrer_external: ?string,
 *   link_text: ?string,
 *   client_ip: string,
 *   user_agent: ?string,
 *   user_id: ?int,
 *   clicked_at: string
 * }
 */
final class ExternalLinkClickRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array{
     *     destination_url: string,
     *     destination_host: string,
     *     source_path: string,
     *     source_url?: ?string,
     *     referrer_external?: ?string,
     *     link_text?: ?string,
     *     client_ip: string,
     *     user_agent?: ?string,
     *     user_id?: ?int
     * } $click
     */
    public function insert(array $click): void
    {
        $hash = self::destinationHash($click['destination_url']);
        $stmt = $this->pdo->prepare(
            'INSERT INTO cms_external_link_clicks
                (destination_url, destination_url_hash, destination_host, source_path,
                 source_url, referrer_external, link_text, client_ip, user_agent, user_id)
             VALUES
                (:destination_url, :destination_url_hash, :destination_host, :source_path,
                 :source_url, :referrer_external, :link_text, :client_ip, :user_agent, :user_id)'
        );
        $stmt->execute([
            'destination_url' => self::truncate($click['destination_url'], 2048),
            'destination_url_hash' => $hash,
            'destination_host' => self::truncate($click['destination_host'], 255),
            'source_path' => self::truncate($click['source_path'], 512),
            'source_url' => isset($click['source_url']) ? self::truncate($click['source_url'], 2048) : null,
            'referrer_external' => isset($click['referrer_external']) ? self::truncate($click['referrer_external'], 2048) : null,
            'link_text' => isset($click['link_text']) ? self::truncate($click['link_text'], 255) : null,
            'client_ip' => self::truncate($click['client_ip'], 45),
            'user_agent' => isset($click['user_agent']) ? self::truncate($click['user_agent'], 512) : null,
            'user_id' => isset($click['user_id']) && $click['user_id'] > 0 ? (int) $click['user_id'] : null,
        ]);
    }

    public function totalClicksSince(?int $sinceTimestamp): int
    {
        if ($sinceTimestamp === null) {
            $stmt = $this->pdo->query('SELECT COUNT(*) FROM cms_external_link_clicks');

            return $stmt instanceof \PDOStatement ? (int) $stmt->fetchColumn() : 0;
        }

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM cms_external_link_clicks WHERE clicked_at >= :since'
        );
        $stmt->execute(['since' => date('Y-m-d H:i:s', $sinceTimestamp)]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return list<array{destination_url: string, destination_host: string, clicks: int, unique_sources: int, last_clicked_at: string}>
     */
    public function topDestinations(int $limit, ?int $sinceTimestamp = null): array
    {
        $limit = max(1, min(500, $limit));
        $where = '';
        $params = [];
        if ($sinceTimestamp !== null) {
            $where = 'WHERE clicked_at >= :since';
            $params['since'] = date('Y-m-d H:i:s', $sinceTimestamp);
        }
        $sql = "SELECT destination_url, destination_host,
                       COUNT(*) AS clicks,
                       COUNT(DISTINCT source_path) AS unique_sources,
                       MAX(clicked_at) AS last_clicked_at
                FROM cms_external_link_clicks
                {$where}
                GROUP BY destination_url_hash, destination_url, destination_host
                ORDER BY clicks DESC, last_clicked_at DESC
                LIMIT {$limit}";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        /** @var list<array{destination_url: string, destination_host: string, clicks: int, unique_sources: int, last_clicked_at: string}> $out */
        $out = [];
        while (($r = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $out[] = [
                'destination_url' => (string) $r['destination_url'],
                'destination_host' => (string) $r['destination_host'],
                'clicks' => (int) $r['clicks'],
                'unique_sources' => (int) $r['unique_sources'],
                'last_clicked_at' => (string) $r['last_clicked_at'],
            ];
        }

        return $out;
    }

    /**
     * @return list<array{source_path: string, clicks: int, unique_destinations: int, last_clicked_at: string}>
     */
    public function topSourcePages(int $limit, ?int $sinceTimestamp = null): array
    {
        $limit = max(1, min(500, $limit));
        $where = '';
        $params = [];
        if ($sinceTimestamp !== null) {
            $where = 'WHERE clicked_at >= :since';
            $params['since'] = date('Y-m-d H:i:s', $sinceTimestamp);
        }
        $sql = "SELECT source_path,
                       COUNT(*) AS clicks,
                       COUNT(DISTINCT destination_url_hash) AS unique_destinations,
                       MAX(clicked_at) AS last_clicked_at
                FROM cms_external_link_clicks
                {$where}
                GROUP BY source_path
                ORDER BY clicks DESC, last_clicked_at DESC
                LIMIT {$limit}";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        /** @var list<array{source_path: string, clicks: int, unique_destinations: int, last_clicked_at: string}> $out */
        $out = [];
        while (($r = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $out[] = [
                'source_path' => (string) $r['source_path'],
                'clicks' => (int) $r['clicks'],
                'unique_destinations' => (int) $r['unique_destinations'],
                'last_clicked_at' => (string) $r['last_clicked_at'],
            ];
        }

        return $out;
    }

    /**
     * @return list<array{
     *     destination_url: string,
     *     destination_host: string,
     *     source_path: string,
     *     source_url: ?string,
     *     referrer_external: ?string,
     *     link_text: ?string,
     *     clicked_at: string,
     *     user_id: ?int
     * }>
     */
    public function recent(int $limit): array
    {
        $limit = max(1, min(500, $limit));
        $sql = "SELECT destination_url, destination_host, source_path, source_url,
                       referrer_external, link_text, clicked_at, user_id
                FROM cms_external_link_clicks
                ORDER BY id DESC
                LIMIT {$limit}";
        $stmt = $this->pdo->query($sql);
        if (!$stmt instanceof \PDOStatement) {
            return [];
        }

        $out = [];
        while (($r = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $out[] = [
                'destination_url' => (string) $r['destination_url'],
                'destination_host' => (string) $r['destination_host'],
                'source_path' => (string) $r['source_path'],
                'source_url' => $r['source_url'] !== null ? (string) $r['source_url'] : null,
                'referrer_external' => $r['referrer_external'] !== null ? (string) $r['referrer_external'] : null,
                'link_text' => $r['link_text'] !== null ? (string) $r['link_text'] : null,
                'clicked_at' => (string) $r['clicked_at'],
                'user_id' => $r['user_id'] !== null ? (int) $r['user_id'] : null,
            ];
        }

        return $out;
    }

    public function purgeOlderThan(int $daysOld): int
    {
        $daysOld = max(1, $daysOld);
        $stmt = $this->pdo->prepare(
            'DELETE FROM cms_external_link_clicks WHERE clicked_at < (NOW() - INTERVAL :d DAY)'
        );
        $stmt->execute(['d' => $daysOld]);

        return $stmt->rowCount();
    }

    public static function destinationHash(string $url): string
    {
        return hash('sha256', strtolower(trim($url)));
    }

    private static function truncate(string $value, int $max): string
    {
        if ($max <= 0) {
            return '';
        }
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $max, 'UTF-8');
        }

        return substr($value, 0, $max);
    }
}
