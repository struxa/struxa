<?php

declare(strict_types=1);

namespace App\Ai;

use PDO;
use PDOException;

final class AiUsageRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function record(int $userId, string $eventType, ?string $metaJson = null): void
    {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO cms_ai_usage_events (user_id, event_type, meta_json, created_at) VALUES (?, ?, ?, UTC_TIMESTAMP())'
            );
            $stmt->execute([$userId, $eventType, $metaJson]);
        } catch (PDOException) {
            // Table missing until migration 025 — avoid breaking chat/draft flows.
        }
    }

    public function countChatsSince(int $userId, int $hours, string $type = 'chat'): int
    {
        $hours = max(1, min(168, $hours));

        return $this->countSince($userId, $type, $hours);
    }

    public function countDraftsSince(int $userId, int $hours): int
    {
        $hours = max(1, min(720, $hours));

        return $this->countSince($userId, 'draft', $hours);
    }

    private function countSince(int $userId, string $eventType, int $hours): int
    {
        try {
            $sql = 'SELECT COUNT(*) FROM cms_ai_usage_events
             WHERE user_id = ? AND event_type = ? AND created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . $hours . ' HOUR)';
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$userId, $eventType]);

            return (int) $stmt->fetchColumn();
        } catch (PDOException) {
            return 0;
        }
    }

    /**
     * @return array{chat_24h: int, draft_24h: int, chat_7d: int, draft_7d: int}
     */
    public function totalsForUser(int $userId): array
    {
        return [
            'chat_24h' => $this->countEvents($userId, 'chat', 24),
            'draft_24h' => $this->countEvents($userId, 'draft', 24),
            'chat_7d' => $this->countEvents($userId, 'chat', 24 * 7),
            'draft_7d' => $this->countEvents($userId, 'draft', 24 * 7),
        ];
    }

    /**
     * @return list<array{user_id: int, email: string, chat_7d: int, draft_7d: int}>
     */
    public function topUsersByVolume7d(int $limit = 15): array
    {
        $limit = max(1, min(50, $limit));
        $sql = 'SELECT e.user_id, COALESCE(u.email, \'\') AS email,
            SUM(CASE WHEN e.event_type = \'chat\' THEN 1 ELSE 0 END) AS chat_7d,
            SUM(CASE WHEN e.event_type = \'draft\' THEN 1 ELSE 0 END) AS draft_7d
            FROM cms_ai_usage_events e
            LEFT JOIN cms_users u ON u.id = e.user_id
            WHERE e.created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)
            GROUP BY e.user_id, u.email
            ORDER BY (chat_7d + draft_7d) DESC
            LIMIT ' . $limit;
        try {
            $stmt = $this->pdo->query($sql);
            if ($stmt === false) {
                return [];
            }
            $out = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $out[] = [
                    'user_id' => (int) ($row['user_id'] ?? 0),
                    'email' => (string) ($row['email'] ?? ''),
                    'chat_7d' => (int) ($row['chat_7d'] ?? 0),
                    'draft_7d' => (int) ($row['draft_7d'] ?? 0),
                ];
            }

            return $out;
        } catch (PDOException) {
            return [];
        }
    }

    private function countEvents(int $userId, string $type, int $hours): int
    {
        $hours = max(1, min(24 * 30, $hours));
        try {
            $stmt = $this->pdo->prepare(
                "SELECT COUNT(*) FROM cms_ai_usage_events
             WHERE user_id = ? AND event_type = ? AND created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL {$hours} HOUR)"
            );
            $stmt->execute([$userId, $type]);

            return (int) $stmt->fetchColumn();
        } catch (PDOException) {
            return 0;
        }
    }
}
