<?php

declare(strict_types=1);

namespace App\Comment;

use PDO;

final class CommentLikeRepository
{
    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    public function countForComment(int $commentId): int
    {
        if ($commentId < 1 || !CommentRepository::pdoHasCommentLikesTable($this->pdo)) {
            return 0;
        }
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM cms_comment_likes WHERE comment_id = :id');
        $st->execute([':id' => $commentId]);

        return (int) $st->fetchColumn();
    }

    /**
     * @return array{liked: bool, count: int}
     */
    public function toggle(int $commentId, int $userId): array
    {
        if ($commentId < 1 || $userId < 1 || !CommentRepository::pdoHasCommentLikesTable($this->pdo)) {
            return ['liked' => false, 'count' => 0];
        }
        $st = $this->pdo->prepare(
            'SELECT 1 FROM cms_comment_likes WHERE comment_id = :cid AND user_id = :uid LIMIT 1'
        );
        $st->execute([':cid' => $commentId, ':uid' => $userId]);
        if ($st->fetchColumn() !== false) {
            $del = $this->pdo->prepare(
                'DELETE FROM cms_comment_likes WHERE comment_id = :cid AND user_id = :uid LIMIT 1'
            );
            $del->execute([':cid' => $commentId, ':uid' => $userId]);
            $liked = false;
        } else {
            $ins = $this->pdo->prepare(
                'INSERT IGNORE INTO cms_comment_likes (comment_id, user_id) VALUES (:cid, :uid)'
            );
            $ins->execute([':cid' => $commentId, ':uid' => $userId]);
            $liked = true;
        }

        return ['liked' => $liked, 'count' => $this->countForComment($commentId)];
    }
}
