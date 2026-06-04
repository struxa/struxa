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

    public const SORT_RATING_ASC = 'rating_asc';

    public const SORT_DOWNLOADS = 'downloads';

    public const SORT_DOWNLOADS_ASC = 'downloads_asc';

    public const SORT_COMMENTS = 'comments';

    public const SORT_COMMENTS_ASC = 'comments_asc';

    public const PER_PAGE = 12;

    /** @var list<string> */
    private const ALLOWED_SORTS = [
        self::SORT_LATEST,
        self::SORT_OLDEST,
        self::SORT_RATING,
        self::SORT_RATING_ASC,
        self::SORT_DOWNLOADS,
        self::SORT_DOWNLOADS_ASC,
        self::SORT_COMMENTS,
        self::SORT_COMMENTS_ASC,
    ];

    public static function normalizeSort(string $raw): string
    {
        $raw = strtolower(trim($raw));

        return in_array($raw, self::ALLOWED_SORTS, true)
            ? $raw
            : self::SORT_LATEST;
    }

    /**
     * Page numbers for browse pagination UI. {@see null} entries render as an ellipsis.
     *
     * @return list<int|null>
     */
    public static function pageLinks(int $current, int $totalPages): array
    {
        if ($totalPages <= 1) {
            return [];
        }
        if ($totalPages <= 7) {
            return range(1, $totalPages);
        }

        $set = [1, $totalPages];
        for ($p = max(2, $current - 1); $p <= min($totalPages - 1, $current + 1); $p++) {
            $set[] = $p;
        }
        sort($set);

        $out = [];
        $prev = 0;
        foreach ($set as $p) {
            if ($prev > 0 && $p - $prev > 1) {
                $out[] = null;
            }
            $out[] = $p;
            $prev = $p;
        }

        return $out;
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
                self::SORT_RATING_ASC => $this->compareRatingAsc($a, $b),
                self::SORT_DOWNLOADS => $this->compareIntMetric($a, $b, 'download_count', false),
                self::SORT_DOWNLOADS_ASC => $this->compareIntMetric($a, $b, 'download_count', true),
                self::SORT_COMMENTS => $this->compareIntMetric($a, $b, 'comment_count', false),
                self::SORT_COMMENTS_ASC => $this->compareIntMetric($a, $b, 'comment_count', true),
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
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    private function compareRatingAsc(array $a, array $b): int
    {
        $ca = (int) ($a['review_count'] ?? 0);
        $cb = (int) ($b['review_count'] ?? 0);

        if ($ca === 0 && $cb === 0) {
            return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        }
        if ($ca === 0) {
            return 1;
        }
        if ($cb === 0) {
            return -1;
        }

        $ra = (float) ($a['rating_average'] ?? 0);
        $rb = (float) ($b['rating_average'] ?? 0);
        $avgCmp = $ra <=> $rb;
        if ($avgCmp !== 0) {
            return $avgCmp;
        }
        $countCmp = $ca <=> $cb;
        if ($countCmp !== 0) {
            return $countCmp;
        }

        return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    private function compareIntMetric(array $a, array $b, string $key, bool $asc): int
    {
        $va = (int) ($a[$key] ?? 0);
        $vb = (int) ($b[$key] ?? 0);
        if ($va === $vb) {
            return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        }
        $cmp = $vb <=> $va;

        return $asc ? -$cmp : $cmp;
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
