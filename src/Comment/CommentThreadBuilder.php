<?php

declare(strict_types=1);

namespace App\Comment;

final class CommentThreadBuilder
{
    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function toTree(array $rows): array
    {
        $byId = [];
        $roots = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $row['children'] = [];
            $byId[$id] = $row;
        }
        foreach ($byId as $id => $row) {
            $parentId = array_key_exists('parent_id', $row) && $row['parent_id'] !== null ? (int) $row['parent_id'] : null;
            if ($parentId !== null && isset($byId[$parentId])) {
                $byId[$parentId]['children'][] = &$byId[$id];
            } else {
                $roots[] = &$byId[$id];
            }
        }

        return $roots;
    }
}
