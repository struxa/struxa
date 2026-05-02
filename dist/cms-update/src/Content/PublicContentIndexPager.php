<?php

declare(strict_types=1);

namespace App\Content;

/**
 * Builds a compact page list for public content type indexes (Twig-friendly).
 *
 * @return list<int|'gap'> Page numbers and 'gap' for an ellipsis between non-adjacent pages.
 */
final class PublicContentIndexPager
{
    /**
     * @return list<int|'gap'>
     */
    public static function pageItems(int $currentPage, int $totalPages, int $radius = 2): array
    {
        if ($totalPages < 1) {
            return [];
        }

        if ($totalPages === 1) {
            return [1];
        }

        $window = range(
            max(1, $currentPage - $radius),
            min($totalPages, $currentPage + $radius)
        );
        $nums = array_values(array_unique(array_merge([1, $totalPages], $window)));
        sort($nums);

        $out = [];
        $prev = 0;
        foreach ($nums as $n) {
            if ($prev > 0 && $n - $prev > 1) {
                $out[] = 'gap';
            }
            $out[] = $n;
            $prev = $n;
        }

        return $out;
    }
}
