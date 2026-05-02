<?php

declare(strict_types=1);

namespace App\Access;

use PDO;

final class PermissionService
{
    /**
     * @return list<string>
     */
    public function permissionSlugsForUser(PDO $pdo, int $cmsUserId): array
    {
        $sql = 'SELECT DISTINCT p.slug FROM cms_permissions p
            INNER JOIN cms_permission_role pr ON pr.permission_id = p.id
            INNER JOIN cms_role_user ru ON ru.role_id = pr.role_id
            WHERE ru.user_id = ? ORDER BY p.slug ASC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$cmsUserId]);
        $out = [];
        while ($s = $stmt->fetchColumn()) {
            $out[] = (string) $s;
        }

        return $out;
    }

    /**
     * @param list<string> $slugs
     */
    public function userHasAny(PDO $pdo, int $cmsUserId, array $slugs): bool
    {
        if ($slugs === []) {
            return true;
        }
        $have = $this->permissionSlugsForUser($pdo, $cmsUserId);

        foreach ($slugs as $need) {
            if (in_array($need, $have, true)) {
                return true;
            }
        }

        return false;
    }

    public function userHas(PDO $pdo, int $cmsUserId, string $slug): bool
    {
        return $this->userHasAny($pdo, $cmsUserId, [$slug]);
    }

    public function canAccessAdmin(PDO $pdo, int $cmsUserId): bool
    {
        return $this->userHas($pdo, $cmsUserId, PermissionSlug::ACCESS_ADMIN);
    }
}
