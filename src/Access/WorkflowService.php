<?php

declare(strict_types=1);

namespace App\Access;

/**
 * Editorial status transitions vs permission slugs.
 */
final class WorkflowService
{
    public const STATUSES = ['draft', 'in_review', 'approved', 'published', 'archived'];

    /**
     * @param list<string> $perms
     */
    public function canTransition(array $perms, string $from, string $to): bool
    {
        $from = strtolower(trim($from));
        $to = strtolower(trim($to));
        if ($from === $to) {
            return true;
        }
        if (!in_array($from, self::STATUSES, true) || !in_array($to, self::STATUSES, true)) {
            return false;
        }

        $canEdit = $this->has($perms, PermissionSlug::EDIT_CONTENT)
            || $this->has($perms, PermissionSlug::MANAGE_PAGES);
        $review = $this->has($perms, PermissionSlug::REVIEW_CONTENT);
        $publish = $this->has($perms, PermissionSlug::PUBLISH_CONTENT);
        $delete = $this->has($perms, PermissionSlug::DELETE_CONTENT);

        return match (true) {
            $from === 'draft' && $to === 'in_review' => $canEdit,
            $from === 'draft' && $to === 'published' => $publish,
            $from === 'draft' && $to === 'approved' => $review && $publish,
            $from === 'draft' && $to === 'archived' => $publish || $delete,
            $from === 'in_review' && $to === 'approved' => $review,
            $from === 'in_review' && $to === 'draft' => $review || $canEdit,
            $from === 'in_review' && $to === 'published' => $publish,
            $from === 'in_review' && $to === 'archived' => $review || $publish || $delete,
            $from === 'approved' && $to === 'published' => $publish,
            $from === 'approved' && $to === 'draft' => $canEdit,
            $from === 'approved' && $to === 'in_review' => $review,
            $from === 'approved' && $to === 'archived' => $publish || $delete,
            $from === 'published' && $to === 'archived' => $publish || $delete,
            $from === 'published' && $to === 'draft' => $canEdit && $publish,
            $from === 'archived' && $to === 'draft' => $canEdit,
            $from === 'archived' && $to === 'published' => $publish,
            default => false,
        };
    }

    /**
     * @param list<string> $perms
     * @return list<string>
     */
    public function allowedTargets(array $perms, string $from): array
    {
        $out = [];
        foreach (self::STATUSES as $to) {
            if ($this->canTransition($perms, $from, $to)) {
                $out[] = $to;
            }
        }

        return $out;
    }

    /**
     * @param list<string> $perms
     */
    private function has(array $perms, string $slug): bool
    {
        return in_array($slug, $perms, true);
    }
}
