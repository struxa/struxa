<?php

declare(strict_types=1);

namespace App\Comment;

use PDO;
use PDOException;

final class CommentRepository
{
    /** @var array<int, bool> spl_object_id(PDO) => likes table readable */
    private static array $pdoHasLikesTable = [];

    /** @var array<int, bool> spl_object_id(PDO) => cms_comments.user_id exists */
    private static array $pdoHasCommentsUserId = [];

    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    /**
     * True when the cms_comment_likes table exists and is readable (migration 032). Probed once per PDO via a trivial SELECT.
     */
    public static function pdoHasCommentLikesTable(PDO $pdo): bool
    {
        $id = spl_object_id($pdo);
        if (!array_key_exists($id, self::$pdoHasLikesTable)) {
            try {
                $pdo->query('SELECT 1 FROM `cms_comment_likes` LIMIT 1');
                self::$pdoHasLikesTable[$id] = true;
            } catch (PDOException) {
                self::$pdoHasLikesTable[$id] = false;
            }
        }

        return self::$pdoHasLikesTable[$id];
    }

    /**
     * True when cms_comments has a user_id column (migration 032). Probed once per PDO.
     */
    public static function pdoHasCommentsUserIdColumn(PDO $pdo): bool
    {
        $id = spl_object_id($pdo);
        if (!array_key_exists($id, self::$pdoHasCommentsUserId)) {
            try {
                $pdo->query('SELECT `user_id` FROM `cms_comments` LIMIT 1');
                self::$pdoHasCommentsUserId[$id] = true;
            } catch (PDOException) {
                self::$pdoHasCommentsUserId[$id] = false;
            }
        }

        return self::$pdoHasCommentsUserId[$id];
    }

    /**
     * Loads a paginated comment thread; on any failure returns an empty pack so storefront pages do not 500.
     *
     * @return array{
     *   rows: list<array<string, mixed>>,
     *   total_roots: int,
     *   page: int,
     *   per_page: int,
     *   total_pages: int
     * }
     */
    public static function loadThreadPagePackSafe(
        PDO $pdo,
        string $threadKey,
        int $page,
        int $rootsPerPage,
        ?int $viewerUserId,
    ): array {
        try {
            return (new self($pdo))->listApprovedThreadPage($threadKey, $page, $rootsPerPage, $viewerUserId);
        } catch (\Throwable $e) {
            error_log('[Struxa] comment thread load failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            $per = max(3, min(30, $rootsPerPage));

            return [
                'rows' => [],
                'total_roots' => 0,
                'page' => 1,
                'per_page' => $per,
                'total_pages' => 1,
            ];
        }
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

        if (self::pdoHasCommentsUserIdColumn($this->pdo)) {
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
        } else {
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
        }

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listApprovedForThread(string $threadKey, int $limit = 400, ?int $viewerUserId = null): array
    {
        $limit = max(1, min(1000, $limit));
        [$likeSub, $likedSub] = $this->likeSelectSqlFragments($viewerUserId);
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

    /**
     * Paginates by top-level (root) comments; each page includes every approved reply under those roots.
     *
     * @return array{
     *   rows: list<array<string, mixed>>,
     *   total_roots: int,
     *   page: int,
     *   per_page: int,
     *   total_pages: int
     * }
     */
    public function listApprovedThreadPage(string $threadKey, int $page, int $rootsPerPage, ?int $viewerUserId): array
    {
        $rootsPerPage = max(3, min(30, $rootsPerPage));
        $totalRoots = $this->countApprovedRootComments($threadKey);
        $totalPages = $totalRoots > 0 ? (int) ceil($totalRoots / $rootsPerPage) : 1;
        $page = max(1, min($page, $totalPages));
        $offset = ($page - 1) * $rootsPerPage;

        $rootIds = $this->approvedRootIdsPaged($threadKey, $offset, $rootsPerPage);
        if ($rootIds === []) {
            return [
                'rows' => [],
                'total_roots' => $totalRoots,
                'page' => $page,
                'per_page' => $rootsPerPage,
                'total_pages' => $totalPages,
            ];
        }

        $allIds = $this->collectDescendantIdsForSeeds($threadKey, $rootIds);
        $rows = $this->fetchApprovedRowsByIds($threadKey, $allIds, $viewerUserId);

        return [
            'rows' => $rows,
            'total_roots' => $totalRoots,
            'page' => $page,
            'per_page' => $rootsPerPage,
            'total_pages' => $totalPages,
        ];
    }

    public function countApprovedRootComments(string $threadKey): int
    {
        $st = $this->pdo->prepare(
            'SELECT COUNT(*) FROM cms_comments
             WHERE thread_key = :thread_key AND status = :status AND parent_id IS NULL'
        );
        $st->execute([':thread_key' => $threadKey, ':status' => 'approved']);

        return (int) $st->fetchColumn();
    }

    /**
     * @return list<int>
     */
    private function approvedRootIdsPaged(string $threadKey, int $offset, int $limit): array
    {
        $sql = 'SELECT id FROM cms_comments
             WHERE thread_key = :thread_key AND status = :status AND parent_id IS NULL
             ORDER BY created_at ASC
             LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;
        $st = $this->pdo->prepare($sql);
        $st->execute([':thread_key' => $threadKey, ':status' => 'approved']);
        $out = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            if (is_array($row) && isset($row['id'])) {
                $out[] = (int) $row['id'];
            }
        }

        return $out;
    }

    /**
     * @param list<int> $seedIds root comment ids
     *
     * @return list<int> seed ids plus all deeper descendants in the same thread
     */
    private function collectDescendantIdsForSeeds(string $threadKey, array $seedIds): array
    {
        $idSet = [];
        foreach ($seedIds as $id) {
            if ($id > 0) {
                $idSet[$id] = true;
            }
        }
        $frontier = array_keys($idSet);
        for ($depth = 0; $depth < 8 && $frontier !== []; ++$depth) {
            $placeholders = implode(',', array_fill(0, count($frontier), '?'));
            $sql = 'SELECT id FROM cms_comments
                 WHERE thread_key = ? AND status = ? AND parent_id IN (' . $placeholders . ')';
            $params = array_merge([$threadKey, 'approved'], $frontier);
            $st = $this->pdo->prepare($sql);
            $st->execute($params);
            $next = [];
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                if (!is_array($row)) {
                    continue;
                }
                $cid = (int) ($row['id'] ?? 0);
                if ($cid < 1 || isset($idSet[$cid])) {
                    continue;
                }
                $idSet[$cid] = true;
                $next[] = $cid;
            }
            $frontier = $next;
        }

        return array_keys($idSet);
    }

    /**
     * @param list<int> $ids
     *
     * @return list<array<string, mixed>>
     */
    private function fetchApprovedRowsByIds(string $threadKey, array $ids, ?int $viewerUserId): array
    {
        if ($ids === []) {
            return [];
        }
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }
        [$likeSub, $likedSub] = $this->likeSelectSqlFragments($viewerUserId);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = 'SELECT c.id, c.thread_key, c.parent_id, c.depth, c.author_name, c.author_email_hash, c.body, c.created_at, '
            . $likeSub . ' AS like_count, ' . $likedSub . ' AS liked_by_me
             FROM cms_comments c
             WHERE c.thread_key = ? AND c.status = ? AND c.id IN (' . $placeholders . ')
             ORDER BY c.created_at ASC';
        $params = array_merge([$threadKey, 'approved'], $ids);
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
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

    /**
     * @return array{0: string, 1: string} SQL fragments (not aliases) for like_count and liked_by_me
     */
    private function likeSelectSqlFragments(?int $viewerUserId): array
    {
        if (!self::pdoHasCommentLikesTable($this->pdo)) {
            return ['0', '0'];
        }
        $likeSub = '(SELECT COUNT(*) FROM cms_comment_likes l WHERE l.comment_id = c.id)';
        if ($viewerUserId !== null && $viewerUserId > 0) {
            $likedSub = 'EXISTS(SELECT 1 FROM cms_comment_likes l2 WHERE l2.comment_id = c.id AND l2.user_id = ' . (int) $viewerUserId . ')';
        } else {
            $likedSub = '0';
        }

        return [$likeSub, $likedSub];
    }
}
