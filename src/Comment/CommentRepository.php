<?php

declare(strict_types=1);

namespace App\Comment;

use PDO;
use PDOException;

final class CommentRepository
{
    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    public function findById(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }
        $st = $this->pdo->prepare('SELECT * FROM cms_comments WHERE id = :id LIMIT 1');
        $st->execute([':id' => $id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function create(array $clean, string $clientIp, string $userAgent, bool $requireApproval): int
    {
        $depth = 0;
        $parentId = $clean['parent_id'];
        if ($parentId !== null) {
            $parent = $this->findById((int) $parentId);
            if ($parent === null || (string) ($parent['thread_key'] ?? '') !== $clean['thread_key']) {
                throw new \RuntimeException('Reply target no longer exists.');
            }
            $depth = min(4, ((int) ($parent['depth'] ?? 0)) + 1);
        }

        $status = $requireApproval ? 'pending' : 'approved';
        $approvedAt = $status === 'approved' ? date('Y-m-d H:i:s') : null;

        $userId = isset($clean['user_id']) && is_int($clean['user_id']) && $clean['user_id'] > 0
            ? $clean['user_id']
            : null;

        $st = $this->pdo->prepare(
            'INSERT INTO cms_comments
            (thread_key, user_id, parent_id, depth, status, author_name, author_email_hash, body, body_html, client_ip, user_agent, approved_at)
            VALUES (:thread_key, :user_id, :parent_id, :depth, :status, :author_name, :author_email_hash, :body, :body_html, :client_ip, :user_agent, :approved_at)'
        );
        $st->execute([
            ':thread_key' => $clean['thread_key'],
            ':user_id' => $userId,
            ':parent_id' => $parentId,
            ':depth' => $depth,
            ':status' => $status,
            ':author_name' => $clean['author_name'],
            ':author_email_hash' => $clean['author_email_hash'],
            ':body' => $clean['body'],
            ':body_html' => $clean['body_html'],
            ':client_ip' => $clientIp,
            ':user_agent' => $userAgent !== '' ? substr($userAgent, 0, 255) : null,
            ':approved_at' => $approvedAt,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listApprovedForThread(string $threadKey, int $limit = 400, ?int $viewerUserId = null): array
    {
        $limit = max(1, min(1000, $limit));
        $likeSub = '(SELECT COUNT(*) FROM cms_comment_likes l WHERE l.comment_id = c.id)';
        if ($viewerUserId !== null && $viewerUserId > 0) {
            $likedSub = 'EXISTS(SELECT 1 FROM cms_comment_likes l2 WHERE l2.comment_id = c.id AND l2.user_id = ' . (int) $viewerUserId . ')';
        } else {
            $likedSub = '0';
        }
        $sql = 'SELECT c.id, c.thread_key, c.parent_id, c.depth, c.author_name, c.author_email_hash, c.body, c.created_at, '
            . $likeSub . ' AS like_count, ' . $likedSub . ' AS liked_by_me
             FROM cms_comments c
             WHERE c.thread_key = :thread_key AND c.status = :status
             ORDER BY c.created_at ASC
             LIMIT ' . (int) $limit;
        $st = $this->pdo->prepare($sql);
        $st->execute([':thread_key' => $threadKey, ':status' => 'approved']);

        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
            $row['like_count'] = (int) ($row['like_count'] ?? 0);
            $row['liked_by_me'] = ((int) ($row['liked_by_me'] ?? 0)) === 1;
        }
        unset($row);

        return $rows;
    }

    public function findApprovedInThread(int $commentId, string $threadKey): ?array
    {
        if ($commentId < 1) {
            return null;
        }
        $st = $this->pdo->prepare(
            'SELECT * FROM cms_comments WHERE id = :id AND thread_key = :tk AND status = :st LIMIT 1'
        );
        $st->execute([':id' => $commentId, ':tk' => $threadKey, ':st' => 'approved']);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function listForAdmin(string $status, int $limit = 300): array
    {
        $allow = ['pending', 'approved', 'rejected', 'spam'];
        if (!in_array($status, $allow, true)) {
            $status = 'pending';
        }
        $limit = max(1, min(1000, $limit));
        $st = $this->pdo->prepare(
            'SELECT id, thread_key, parent_id, depth, status, author_name, body, body_html, client_ip, created_at
             FROM cms_comments
             WHERE status = :status
             ORDER BY created_at DESC
             LIMIT ' . (int) $limit
        );
        $st->execute([':status' => $status]);

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function countByStatus(string $status): int
    {
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM cms_comments WHERE status = :status');
        $st->execute([':status' => $status]);

        return (int) $st->fetchColumn();
    }

    public function setStatus(int $id, string $status): bool
    {
        $allow = ['pending', 'approved', 'rejected', 'spam'];
        if ($id < 1 || !in_array($status, $allow, true)) {
            return false;
        }
        $approvedAt = $status === 'approved' ? date('Y-m-d H:i:s') : null;
        $st = $this->pdo->prepare('UPDATE cms_comments SET status = :status, approved_at = :approved_at WHERE id = :id LIMIT 1');
        $st->execute([':status' => $status, ':approved_at' => $approvedAt, ':id' => $id]);

        return $st->rowCount() > 0;
    }

    public function delete(int $id): bool
    {
        if ($id < 1) {
            return false;
        }
        $st = $this->pdo->prepare('DELETE FROM cms_comments WHERE id = :id LIMIT 1');
        $st->execute([':id' => $id]);

        return $st->rowCount() > 0;
    }

    public function hasRecentDuplicate(string $threadKey, string $emailHash, string $body, int $seconds = 180): bool
    {
        $seconds = max(30, min(86400, $seconds));
        try {
            $st = $this->pdo->prepare(
                'SELECT id FROM cms_comments
                 WHERE thread_key = :thread_key
                   AND author_email_hash = :email_hash
                   AND body = :body
                   AND created_at >= DATE_SUB(NOW(), INTERVAL :seconds SECOND)
                 LIMIT 1'
            );
            $st->bindValue(':thread_key', $threadKey);
            $st->bindValue(':email_hash', $emailHash);
            $st->bindValue(':body', $body);
            $st->bindValue(':seconds', $seconds, PDO::PARAM_INT);
            $st->execute();

            return $st->fetch(PDO::FETCH_ASSOC) !== false;
        } catch (PDOException) {
            return false;
        }
    }
}
