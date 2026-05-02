<?php

declare(strict_types=1);

namespace App\Access;

use PDO;

final class RoleRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function allOrdered(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM cms_roles ORDER BY name ASC');
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[] = $row;
        }

        return $out;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM cms_roles WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function findBySlug(string $slug): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM cms_roles WHERE slug = ? LIMIT 1');
        $stmt->execute([$slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * @return int new id
     */
    public function insert(string $name, string $slug, ?string $description): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO cms_roles (name, slug, description, is_system) VALUES (?, ?, ?, 0)'
        );
        $stmt->execute([$name, $slug, $description]);

        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, string $name, string $slug, ?string $description): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE cms_roles SET name = ?, slug = ?, description = ? WHERE id = ? AND is_system = 0'
        );
        $stmt->execute([$name, $slug, $description, $id]);
    }

    public function deleteIfCustom(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM cms_roles WHERE id = ? AND is_system = 0');

        return $stmt->execute([$id]) && $stmt->rowCount() > 0;
    }

    /**
     * @return list<int>
     */
    public function permissionIdsForRole(int $roleId): array
    {
        $stmt = $this->pdo->prepare('SELECT permission_id FROM cms_permission_role WHERE role_id = ?');
        $stmt->execute([$roleId]);
        $out = [];
        while ($id = $stmt->fetchColumn()) {
            $out[] = (int) $id;
        }

        return $out;
    }

    /**
     * @param list<int> $permissionIds
     */
    public function syncPermissions(int $roleId, array $permissionIds): void
    {
        $this->pdo->prepare('DELETE FROM cms_permission_role WHERE role_id = ?')->execute([$roleId]);
        $ins = $this->pdo->prepare('INSERT INTO cms_permission_role (permission_id, role_id) VALUES (?, ?)');
        $seen = [];
        foreach ($permissionIds as $pid) {
            $pid = (int) $pid;
            if ($pid < 1 || isset($seen[$pid])) {
                continue;
            }
            $seen[$pid] = true;
            $ins->execute([$pid, $roleId]);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function allPermissions(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM cms_permissions ORDER BY name ASC');
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[] = $row;
        }

        return $out;
    }
}
