<?php

declare(strict_types=1);

namespace App\Access;

use PDO;

final class ActivityLogRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array<string, mixed> $details
     */
    public function insert(?int $userId, string $eventType, ?string $subjectType, ?int $subjectId, array $details = []): void
    {
        $json = $details === [] ? null : json_encode($details, JSON_THROW_ON_ERROR);
        $stmt = $this->pdo->prepare(
            'INSERT INTO cms_activity_logs (user_id, event_type, subject_type, subject_id, details_json) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$userId, $eventType, $subjectType, $subjectId, $json]);
    }

    public function countAll(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM cms_activity_logs')->fetchColumn();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listOrderedPage(int $limit, int $offset): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $sql = 'SELECT l.*, u.email AS user_email, u.display_name AS user_display_name
                FROM cms_activity_logs l
                LEFT JOIN cms_users u ON u.id = l.user_id
                ORDER BY l.created_at DESC
                LIMIT :lim OFFSET :off';
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[] = $row;
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recent(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));

        return $this->listOrderedPage($limit, 0);
    }
}
