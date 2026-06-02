<?php

declare(strict_types=1);

namespace App\Access;

final class MemberAccessFormParser
{
    /**
     * @param array<string, mixed> $body
     */
    public static function isMembersOnly(array $body): bool
    {
        return !empty($body['members_only']);
    }

    /**
     * @param array<string, mixed> $body
     * @return list<int>
     */
    public static function roleIds(array $body): array
    {
        $raw = $body['member_role_ids'] ?? [];
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $id) {
            if (is_numeric($id)) {
                $rid = (int) $id;
                if ($rid > 0) {
                    $out[] = $rid;
                }
            }
        }

        return array_values(array_unique($out));
    }
}
