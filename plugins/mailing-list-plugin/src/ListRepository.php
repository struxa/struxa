<?php

declare(strict_types=1);

namespace MailingListPlugin;

use PDO;

final class ListRepository
{
    private const TABLE = 'cms_mailing_list_lists';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return list<MailingList>
     */
    public function allOrdered(): array
    {
        $stmt = $this->pdo->query(
            'SELECT * FROM ' . self::TABLE . ' ORDER BY name ASC, id ASC'
        );
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[] = MailingList::fromRow($row);
        }

        return $out;
    }

    /**
     * @return list<MailingList>
     */
    public function activeOrdered(): array
    {
        $stmt = $this->pdo->query(
            'SELECT * FROM ' . self::TABLE . ' WHERE is_active = 1 ORDER BY name ASC, id ASC'
        );
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[] = MailingList::fromRow($row);
        }

        return $out;
    }

    public function findById(int $id): ?MailingList
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ' . self::TABLE . ' WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? MailingList::fromRow($row) : null;
    }

    public function findBySlug(string $slug): ?MailingList
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ' . self::TABLE . ' WHERE slug = ? LIMIT 1');
        $stmt->execute([strtolower(trim($slug))]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? MailingList::fromRow($row) : null;
    }

    public function slugTaken(string $slug, ?int $exceptId = null): bool
    {
        $sql = 'SELECT 1 FROM ' . self::TABLE . ' WHERE slug = ?';
        $params = [strtolower(trim($slug))];
        if ($exceptId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $exceptId;
        }
        $sql .= ' LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchColumn() !== false;
    }

    public function insert(string $slug, string $name, ?string $description, bool $isActive): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . self::TABLE . ' (slug, name, description, is_active) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([
            strtolower(trim($slug)),
            trim($name),
            $description !== null && trim($description) !== '' ? trim($description) : null,
            $isActive ? 1 : 0,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, string $slug, string $name, ?string $description, bool $isActive): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE ' . self::TABLE . ' SET slug = ?, name = ?, description = ?, is_active = ? WHERE id = ?'
        );
        $stmt->execute([
            strtolower(trim($slug)),
            trim($name),
            $description !== null && trim($description) !== '' ? trim($description) : null,
            $isActive ? 1 : 0,
            $id,
        ]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM ' . self::TABLE . ' WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function countSubscribers(int $listId, string $status = 'subscribed'): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM cms_mailing_list_subscribers WHERE list_id = ? AND status = ?'
        );
        $stmt->execute([$listId, $status]);

        return (int) $stmt->fetchColumn();
    }
}
