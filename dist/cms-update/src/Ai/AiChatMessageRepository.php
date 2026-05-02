<?php

declare(strict_types=1);

namespace App\Ai;

use PDO;
use PDOException;

final class AiChatMessageRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function insert(int $userId, string $role, string $content): void
    {
        try {
            $role = $role === 'assistant' ? 'assistant' : 'user';
            $stmt = $this->pdo->prepare(
                'INSERT INTO cms_ai_chat_messages (user_id, role, content, created_at) VALUES (?, ?, ?, UTC_TIMESTAMP())'
            );
            $stmt->execute([$userId, $role, $content]);
        } catch (PDOException) {
            // Table missing until migration 025.
        }
    }

    /**
     * @return list<array{role: string, content: string}>
     */
    public function listForUser(int $userId, int $limit): array
    {
        try {
            $limit = max(1, min(200, $limit));
            $stmt = $this->pdo->prepare(
                'SELECT role, content FROM cms_ai_chat_messages WHERE user_id = ? ORDER BY id ASC LIMIT ' . $limit
            );
            $stmt->execute([$userId]);
            $out = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $out[] = [
                    'role' => (string) ($row['role'] ?? ''),
                    'content' => (string) ($row['content'] ?? ''),
                ];
            }

            return $out;
        } catch (PDOException) {
            return [];
        }
    }

    public function deleteForUser(int $userId): void
    {
        try {
            $stmt = $this->pdo->prepare('DELETE FROM cms_ai_chat_messages WHERE user_id = ?');
            $stmt->execute([$userId]);
        } catch (PDOException) {
        }
    }

    public function deleteLastForUser(int $userId): void
    {
        try {
            $stmt = $this->pdo->prepare(
                'DELETE FROM cms_ai_chat_messages WHERE user_id = ? ORDER BY id DESC LIMIT 1'
            );
            $stmt->execute([$userId]);
        } catch (PDOException) {
        }
    }

    /** @return int rows deleted */
    public function purgeOlderThanDays(int $days): int
    {
        if ($days < 1) {
            return 0;
        }
        try {
            $stmt = $this->pdo->prepare(
                'DELETE FROM cms_ai_chat_messages WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . $days . ' DAY)'
            );
            $stmt->execute();

            return $stmt->rowCount();
        } catch (PDOException) {
            return 0;
        }
    }
}
