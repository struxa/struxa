<?php

declare(strict_types=1);

namespace StruxaAdmin;

/**
 * Enriches catalog entries with download, review, and comment stats.
 */
final class CatalogEntryEngagement
{
    public function __construct(
        private readonly CatalogDownloadStatsRepository $downloads,
        private readonly CatalogReviewRepository $reviews,
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
        $reviewStats = $this->reviews->statsForPackages($packages);
        $commentCounts = $this->comments->countsForPackages($packages);

        $out = [];
        foreach ($entries as $entry) {
            $key = $kind . ':' . (string) ($entry['slug'] ?? '');
            $stats = $reviewStats[$key] ?? ['average' => null, 'count' => 0];
            $out[] = array_merge($entry, [
                'download_count' => $downloadCounts[$key] ?? 0,
                'rating_average' => $stats['average'],
                'review_count' => (int) ($stats['count'] ?? 0),
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
        $reviewStats = $this->reviews->statsFor($kind, $slug);

        return array_merge($entry, [
            'download_count' => $this->downloads->countFor($kind, $slug),
            'rating_average' => $reviewStats['average'],
            'review_count' => $reviewStats['count'],
            'comment_count' => $this->comments->countVisible($kind, $slug),
        ]);
    }
}
