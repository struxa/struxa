<?php

declare(strict_types=1);

namespace App\Access;

use App\CmsUserRepository;
use PDO;

final class MemberAccessService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly MemberAccessRepository $roles,
        private readonly RoleUserRepository $roleUsers,
    ) {
    }

    /**
     * @param list<int> $requiredRoleIds
     */
    public function canView(
        bool $membersOnly,
        array $requiredRoleIds,
        ?int $phpauthUserId,
        bool $userCanAccessAdmin,
    ): bool {
        if (!$membersOnly) {
            return true;
        }
        if ($userCanAccessAdmin) {
            return true;
        }
        if ($phpauthUserId === null || $phpauthUserId < 1) {
            return false;
        }
        if ($requiredRoleIds === []) {
            return true;
        }

        $cmsUser = CmsUserRepository::findByPhpAuthUserId($this->pdo, $phpauthUserId);
        if ($cmsUser === null || (int) ($cmsUser['is_active'] ?? 0) !== 1) {
            return false;
        }

        $have = $this->roleUsers->roleIdsForUser((int) $cmsUser['id']);
        foreach ($requiredRoleIds as $need) {
            if (in_array($need, $have, true)) {
                return true;
            }
        }

        return false;
    }

    public function canViewPage(
        bool $membersOnly,
        int $pageId,
        ?int $phpauthUserId,
        bool $userCanAccessAdmin,
    ): bool {
        $roleIds = $membersOnly ? $this->roles->roleIdsForPage($pageId) : [];

        return $this->canView($membersOnly, $roleIds, $phpauthUserId, $userCanAccessAdmin);
    }

    public function canViewEntry(
        bool $membersOnly,
        int $entryId,
        ?int $phpauthUserId,
        bool $userCanAccessAdmin,
    ): bool {
        $roleIds = $membersOnly ? $this->roles->roleIdsForEntry($entryId) : [];

        return $this->canView($membersOnly, $roleIds, $phpauthUserId, $userCanAccessAdmin);
    }

    /**
     * @param list<array<string, mixed>> $entryRows
     * @return list<array<string, mixed>>
     */
    public function filterEntryRows(array $entryRows, ?int $phpauthUserId, bool $userCanAccessAdmin): array
    {
        if ($userCanAccessAdmin) {
            return $entryRows;
        }
        $out = [];
        foreach ($entryRows as $row) {
            $membersOnly = (bool) ((int) ($row['members_only'] ?? 0));
            if (!$membersOnly) {
                $out[] = $row;
                continue;
            }
            $entryId = (int) ($row['id'] ?? 0);
            if ($entryId > 0 && $this->canViewEntry(true, $entryId, $phpauthUserId, false)) {
                $out[] = $row;
            }
        }

        return $out;
    }
}
