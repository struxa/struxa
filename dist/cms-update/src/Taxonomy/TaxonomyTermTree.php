<?php

declare(strict_types=1);

namespace App\Taxonomy;

/**
 * Builds ordered rows with depth for hierarchical taxonomy admin UI.
 */
final class TaxonomyTermTree
{
    /**
     * @param list<TaxonomyTerm> $terms
     * @return list<array{term: TaxonomyTerm, depth: int}>
     */
    public static function rowsWithDepth(array $terms, bool $hierarchical): array
    {
        if (!$hierarchical || $terms === []) {
            $out = [];
            foreach ($terms as $t) {
                $out[] = ['term' => $t, 'depth' => 0];
            }

            return $out;
        }

        /** @var array<int, list<TaxonomyTerm>> $byParent */
        $byParent = [];
        foreach ($terms as $t) {
            $pid = $t->parentId ?? 0;
            $byParent[$pid] ??= [];
            $byParent[$pid][] = $t;
        }

        $walk = static function (int $parentId, int $depth) use (&$walk, &$byParent, &$out): void {
            foreach ($byParent[$parentId] ?? [] as $t) {
                $out[] = ['term' => $t, 'depth' => $depth];
                $walk($t->id, $depth + 1);
            }
        };

        $out = [];
        $walk(0, 0);

        return $out;
    }
}
