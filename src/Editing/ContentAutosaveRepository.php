<?php

declare(strict_types=1);

namespace App\Editing;

use PDO;

final class ContentAutosaveRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function save(string $subjectType, int $subjectId, int $userId, array $payload): void
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $stmt = $this->pdo->prepare(
            'INSERT INTO cms_autosaves (subject_type, subject_id, user_id, payload_json, updated_at)
             VALUES (?, ?, ?, ?, NOW(6))
             ON DUPLICATE KEY UPDATE payload_json = VALUES(payload_json), updated_at = NOW(6)'
        );
        $stmt->execute([$subjectType, $subjectId, $userId, $json]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findForUser(string $subjectType, int $subjectId, int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM cms_autosaves
             WHERE subject_type = ? AND subject_id = ? AND user_id = ?
             LIMIT 1'
        );
        $stmt->execute([$subjectType, $subjectId, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function deleteForUser(string $subjectType, int $subjectId, int $userId): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM cms_autosaves WHERE subject_type = ? AND subject_id = ? AND user_id = ?'
        );
        $stmt->execute([$subjectType, $subjectId, $userId]);
    }

    public function purgeOlderThan(int $days): int
    {
        $days = max(1, $days);
        $stmt = $this->pdo->prepare(
            'DELETE FROM cms_autosaves WHERE updated_at < DATE_SUB(NOW(6), INTERVAL ? DAY)'
        );
        $stmt->execute([$days]);

        return $stmt->rowCount();
    }
}
