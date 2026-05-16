<?php

declare(strict_types=1);

namespace MailingListPlugin;

use PDO;

final class SubscriberRepository
{
    private const TABLE = 'cms_mailing_list_subscribers';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array{rows: list<Subscriber>, total: int}
     */
    public function forListPaged(int $listId, int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $countStmt = $this->pdo->prepare('SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE list_id = ?');
        $countStmt->execute([$listId]);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $this->pdo->prepare(
            'SELECT * FROM ' . self::TABLE . ' WHERE list_id = ? ORDER BY subscribed_at DESC, id DESC LIMIT '
            . (int) $perPage . ' OFFSET ' . (int) $offset
        );
        $stmt->execute([$listId]);
        $rows = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $rows[] = Subscriber::fromRow($row);
        }

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * @return 'subscribed'|'reactivated'|'already'
     */
    public function subscribe(int $listId, string $email): string
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, status FROM ' . self::TABLE . ' WHERE list_id = ? AND email = ? LIMIT 1'
        );
        $stmt->execute([$listId, $email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            $ins = $this->pdo->prepare(
                'INSERT INTO ' . self::TABLE . ' (list_id, email, status, subscribed_at) VALUES (?, ?, \'subscribed\', UTC_TIMESTAMP())'
            );
            $ins->execute([$listId, $email]);

            return 'subscribed';
        }
        if ((string) $row['status'] === 'subscribed') {
            return 'already';
        }
        $upd = $this->pdo->prepare(
            'UPDATE ' . self::TABLE . ' SET status = \'subscribed\', subscribed_at = UTC_TIMESTAMP(), unsubscribed_at = NULL WHERE id = ?'
        );
        $upd->execute([(int) $row['id']]);

        return 'reactivated';
    }

    public function unsubscribe(int $listId, string $email): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE ' . self::TABLE . ' SET status = \'unsubscribed\', unsubscribed_at = UTC_TIMESTAMP()
             WHERE list_id = ? AND email = ? AND status = \'subscribed\''
        );
        $stmt->execute([$listId, $email]);

        return $stmt->rowCount() > 0;
    }

    public function delete(int $id, int $listId): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM ' . self::TABLE . ' WHERE id = ? AND list_id = ?');
        $stmt->execute([$id, $listId]);

        return $stmt->rowCount() > 0;
    }
}
