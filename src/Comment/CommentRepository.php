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

        $st = $this->pdo->prepare(
            'INSERT INTO cms_comments
            (thread_key, parent_id, depth, status, author_name, author_email_hash, body, body_html, client_ip, user_agent, approved_at)
            VALUES (:thread_key, :parent_id, :depth, :status, :author_name, :author_email_hash, :body, :body_html, :client_ip, :user_agent, :approved_at)'
        );
        $st->execute([
            ':thread_key' => $clean['thread_key'],
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

    public function listApprovedForThread(string $threadKey, int $limit = 400): array
    {
        $limit = max(1, min(1000, $limit));
        $st = $this->pdo->prepare(
            'SELECT id, thread_key, parent_id, depth, author_name, author_email_hash, body_html, created_at
             FROM cms_comments
             WHERE thread_key = :thread_key AND status = :status
             ORDER BY created_at ASC
             LIMIT ' . (int) $limit
        );
        $st->execute([':thread_key' => $threadKey, ':status' => 'approved']);

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function listForAdmin(string $status, int $limit = 300): array
    {
        $allow = ['pending', 'approved', 'rejected', 'spam'];
        if (!in_array($status, $allow, true)) {
            $status = 'pending';
        }
        $limit = max(1, min(1000, $limit));
        $st = $this->pdo->prepare(
            'SELECT id, thread_key, parent_id, depth, status, author_name, body_html, client_ip, created_at
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
