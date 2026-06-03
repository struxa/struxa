<?php

declare(strict_types=1);

namespace StruxaAdmin;

/**
 * Enriches catalog entries with download, rating, and comment stats.
 */
final class CatalogEntryEngagement
{
    public function __construct(
        private readonly CatalogDownloadStatsRepository $downloads,
        private readonly CatalogRatingRepository $ratings,
        private readonly CatalogCommentRepository $comments,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $entries
     *
     * @return list<array<string, mixed>>
     */
    public function enrichList(string $kind, array $entries): array
    {
        if ($entries === []) {
            return [];
        }

        $packages = [];
        foreach ($entries as $entry) {
            $packages[] = ['kind' => $kind, 'slug' => (string) ($entry['slug'] ?? '')];
        }

        $downloadCounts = $this->downloads->countsForPackages($packages);
        $ratingStats = $this->ratings->statsForPackages($packages);
        $commentCounts = $this->comments->countsForPackages($packages);

        $out = [];
        foreach ($entries as $entry) {
            $key = $kind . ':' . (string) ($entry['slug'] ?? '');
            $stats = $ratingStats[$key] ?? ['average' => null, 'count' => 0];
            $out[] = array_merge($entry, [
                'download_count' => $downloadCounts[$key] ?? 0,
                'rating_average' => $stats['average'],
                'rating_count' => (int) ($stats['count'] ?? 0),
                'comment_count' => $commentCounts[$key] ?? 0,
            ]);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $entry
     *
     * @return array<string, mixed>
     */
    public function enrichOne(string $kind, array $entry): array
    {
        $slug = (string) ($entry['slug'] ?? '');
        $ratingStats = $this->ratings->statsFor($kind, $slug);

        return array_merge($entry, [
            'download_count' => $this->downloads->countFor($kind, $slug),
            'rating_average' => $ratingStats['average'],
            'rating_count' => $ratingStats['count'],
            'comment_count' => $this->comments->countVisible($kind, $slug),
        ]);
    }
}
