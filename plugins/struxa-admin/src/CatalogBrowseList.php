<?php

declare(strict_types=1);

namespace StruxaAdmin;

/**
 * Sort and paginate public catalog browse lists.
 */
final class CatalogBrowseList
{
    public const SORT_LATEST = 'latest';

    public const SORT_OLDEST = 'oldest';

    public const SORT_RATING = 'rating';

    public const PER_PAGE = 12;

    public static function normalizeSort(string $raw): string
    {
        $raw = strtolower(trim($raw));

        return in_array($raw, [self::SORT_LATEST, self::SORT_OLDEST, self::SORT_RATING], true)
            ? $raw
            : self::SORT_LATEST;
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return array{
     *   items: list<array<string, mixed>>,
     *   page: int,
     *   pages: int,
     *   total: int,
     *   per_page: int,
     *   sort: string
     * }
     */
    public function apply(string $sort, int $page, array $items): array
    {
        $sort = self::normalizeSort($sort);
        $page = max(1, $page);
        $perPage = self::PER_PAGE;
        $sorted = $this->sortItems($items, $sort);
        $total = count($sorted);
        $pages = max(1, (int) ceil(max(1, $total) / $perPage));
        if ($page > $pages) {
            $page = $pages;
        }
        $offset = ($page - 1) * $perPage;

        return [
            'items' => array_slice($sorted, $offset, $perPage),
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
            'per_page' => $perPage,
            'sort' => $sort,
        ];
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return list<array<string, mixed>>
     */
    private function sortItems(array $items, string $sort): array
    {
        usort($items, function (array $a, array $b) use ($sort): int {
            return match ($sort) {
                self::SORT_OLDEST => $this->compareListedAt($a, $b, true),
                self::SORT_RATING => $this->compareRating($a, $b),
                default => $this->compareListedAt($a, $b, false),
            };
        });

        return $items;
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    private function compareListedAt(array $a, array $b, bool $asc): int
    {
        $ta = $this->listedTimestamp($a);
        $tb = $this->listedTimestamp($b);
        if ($ta === $tb) {
            return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        }
        if ($ta === null) {
            return 1;
        }
        if ($tb === null) {
            return -1;
        }
        $cmp = $ta <=> $tb;

        return $asc ? $cmp : -$cmp;
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    private function compareRating(array $a, array $b): int
    {
        $ca = (int) ($a['review_count'] ?? 0);
        $cb = (int) ($b['review_count'] ?? 0);
        $ra = $a['rating_average'] ?? null;
        $rb = $b['rating_average'] ?? null;

        if ($ca === 0) {
            if ($cb === 0) {
                return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
            }

            return 1;
        }
        if ($cb === 0) {
            return -1;
        }

        $avgCmp = ((float) $rb <=> (float) $ra);
        if ($avgCmp !== 0) {
            return $avgCmp;
        }
        $countCmp = $cb <=> $ca;
        if ($countCmp !== 0) {
            return $countCmp;
        }

        return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function listedTimestamp(array $entry): ?int
    {
        $raw = trim((string) ($entry['listed_at'] ?? ''));
        if ($raw === '') {
            return null;
        }
        $ts = strtotime($raw);

        return $ts !== false ? $ts : null;
    }
}
