<?php

declare(strict_types=1);

namespace App\Access;

/**
 * Declares who may view a public route or resource.
 *
 * Use {@see MemberAccessPolicy::public()} for open access,
 * {@see MemberAccessPolicy::loggedIn()} for any signed-in member,
 * or {@see MemberAccessPolicy::roles()} when specific CMS roles are required.
 */
final class MemberAccessPolicy
{
    /**
     * @param list<int> $roleIds CMS role ids; ignored when $membersOnly is false; empty = any logged-in member
     */
    private function __construct(
        public readonly bool $membersOnly,
        public readonly array $roleIds,
    ) {
    }

    public static function public(): self
    {
        return new self(false, []);
    }

    /** Any logged-in front-end member (staff with admin access always pass). */
    public static function loggedIn(): self
    {
        return new self(true, []);
    }

    /**
     * @param list<int> $roleIds
     */
    public static function roles(array $roleIds): self
    {
        $ids = [];
        foreach ($roleIds as $id) {
            if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                $rid = (int) $id;
                if ($rid > 0) {
                    $ids[] = $rid;
                }
            }
        }

        return new self(true, array_values(array_unique($ids)));
    }

    /**
     * @param list<int> $requiredRoleIds
     */
    public static function fromMembersOnly(bool $membersOnly, array $requiredRoleIds): self
    {
        return $membersOnly ? self::roles($requiredRoleIds) : self::public();
    }
}
