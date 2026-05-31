<?php

declare(strict_types=1);

namespace App\Editing;

use PDO;

final class EditLockRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findForSubject(string $subjectType, int $subjectId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT l.*, u.email AS user_email, u.display_name AS user_display_name
             FROM cms_edit_locks l
             INNER JOIN cms_users u ON u.id = l.user_id
             WHERE l.subject_type = ? AND l.subject_id = ?
             LIMIT 1'
        );
        $stmt->execute([$subjectType, $subjectId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function upsert(string $subjectType, int $subjectId, int $userId, string $lockToken): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO cms_edit_locks (subject_type, subject_id, lock_token, user_id, heartbeat_at)
             VALUES (?, ?, ?, ?, NOW(6))
             ON DUPLICATE KEY UPDATE
               lock_token = VALUES(lock_token),
               user_id = VALUES(user_id),
               heartbeat_at = NOW(6)'
        );
        $stmt->execute([$subjectType, $subjectId, $lockToken, $userId]);
    }

    public function touch(string $subjectType, int $subjectId, int $userId, string $lockToken): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE cms_edit_locks
             SET heartbeat_at = NOW(6)
             WHERE subject_type = ? AND subject_id = ? AND user_id = ? AND lock_token = ?'
        );
        $stmt->execute([$subjectType, $subjectId, $userId, $lockToken]);

        return $stmt->rowCount() > 0;
    }

    public function release(string $subjectType, int $subjectId, int $userId, string $lockToken): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM cms_edit_locks
             WHERE subject_type = ? AND subject_id = ? AND user_id = ? AND lock_token = ?'
        );
        $stmt->execute([$subjectType, $subjectId, $userId, $lockToken]);
    }

    public function deleteExpired(int $ttlSeconds): int
    {
        $ttlSeconds = max(30, $ttlSeconds);
        $stmt = $this->pdo->prepare(
            'DELETE FROM cms_edit_locks WHERE heartbeat_at < DATE_SUB(NOW(6), INTERVAL ? SECOND)'
        );
        $stmt->execute([$ttlSeconds]);

        return $stmt->rowCount();
    }
}
