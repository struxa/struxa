<?php

declare(strict_types=1);

namespace App\Access;

use PDO;

final class RoleUserRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return list<int> role ids
     */
    public function roleIdsForUser(int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT role_id FROM cms_role_user WHERE user_id = ?');
        $stmt->execute([$userId]);
        $out = [];
        while ($id = $stmt->fetchColumn()) {
            $out[] = (int) $id;
        }

        return $out;
    }

    /**
     * @param list<int> $roleIds
     */
    public function replaceForUser(int $userId, array $roleIds): void
    {
        $this->pdo->prepare('DELETE FROM cms_role_user WHERE user_id = ?')->execute([$userId]);
        $ins = $this->pdo->prepare('INSERT INTO cms_role_user (role_id, user_id) VALUES (?, ?)');
        $seen = [];
        foreach ($roleIds as $rid) {
            $rid = (int) $rid;
            if ($rid < 1 || isset($seen[$rid])) {
                continue;
            }
            $seen[$rid] = true;
            $ins->execute([$rid, $userId]);
        }
    }

    /**
     * @return list<array<string, mixed>> roles rows
     */
    public function rolesForUser(int $userId): array
    {
        $sql = 'SELECT r.* FROM cms_roles r
                INNER JOIN cms_role_user ru ON ru.role_id = r.id
                WHERE ru.user_id = ?
                ORDER BY r.name ASC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$userId]);
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[] = $row;
        }

        return $out;
    }
}
