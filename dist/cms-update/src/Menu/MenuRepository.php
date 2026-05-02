<?php

declare(strict_types=1);

namespace App\Menu;

use PDO;

final class MenuRepository
{
    private const TABLE = 'cms_menus';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return list<Menu>
     */
    public function allOrdered(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, name, location, created_at, updated_at FROM ' . self::TABLE . ' ORDER BY FIELD(location, \'header\', \'footer\'), id ASC'
        );
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[] = Menu::fromRow($row);
        }

        return $out;
    }

    public function findById(int $id): ?Menu
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name, location, created_at, updated_at FROM ' . self::TABLE . ' WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : Menu::fromRow($row);
    }

    public function findByLocation(string $location): ?Menu
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name, location, created_at, updated_at FROM ' . self::TABLE . ' WHERE location = ? LIMIT 1'
        );
        $stmt->execute([$location]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : Menu::fromRow($row);
    }

    public function locationTaken(string $location, ?int $exceptMenuId = null): bool
    {
        if ($exceptMenuId === null) {
            $stmt = $this->pdo->prepare('SELECT 1 FROM ' . self::TABLE . ' WHERE location = ? LIMIT 1');
            $stmt->execute([$location]);
        } else {
            $stmt = $this->pdo->prepare(
                'SELECT 1 FROM ' . self::TABLE . ' WHERE location = ? AND id != ? LIMIT 1'
            );
            $stmt->execute([$location, $exceptMenuId]);
        }

        return (bool) $stmt->fetchColumn();
    }

    /**
     * @return int new id
     */
    public function insert(string $name, string $location): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO ' . self::TABLE . ' (name, location) VALUES (?, ?)');
        $stmt->execute([$name, $location]);

        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, string $name, string $location): void
    {
        $stmt = $this->pdo->prepare('UPDATE ' . self::TABLE . ' SET name = ?, location = ? WHERE id = ?');
        $stmt->execute([$name, $location, $id]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM ' . self::TABLE . ' WHERE id = ?');
        $stmt->execute([$id]);
    }
}
