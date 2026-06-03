<?php

declare(strict_types=1);

namespace StruxaAdmin;

use App\CmsUserRepository;

/**
 * View-model helpers for logged-in catalog submitter pages.
 */
final class CatalogMemberContext
{
    /**
     * @param array<string, mixed> $viewData
     *
     * @return array<string, mixed>
     */
    public static function forView(
        \PDO $pdo,
        array $viewData,
        CatalogSubmissionRepository $submissions,
        CatalogDownloadStatsRepository $stats,
        int $recentLimit = 8,
    ): array {
        if (empty($viewData['logged_in'])) {
            return [];
        }

        $phpauthId = isset($viewData['phpauth_user_id']) ? (int) $viewData['phpauth_user_id'] : 0;
        if ($phpauthId <= 0 || !CmsUserRepository::tableExists($pdo)) {
            return [];
        }

        $cmsUser = CmsUserRepository::findByPhpAuthId($pdo, $phpauthId);
        if ($cmsUser === null || (int) ($cmsUser['is_active'] ?? 0) !== 1) {
            return [];
        }

        $cmsUserId = (int) $cmsUser['id'];
        $rows = $submissions->listBySubmitterUserId($cmsUserId, 50);
        $packages = [];
        foreach ($rows as $row) {
            $packages[] = ['kind' => $row->kind, 'slug' => $row->slug];
        }
        $downloadCounts = $stats->countsForPackages($packages);

        $displayName = trim((string) ($viewData['user_display_name'] ?? ''));
        if ($displayName === '') {
            $displayName = trim((string) ($cmsUser['display_name'] ?? ''));
        }
        if ($displayName === '') {
            $displayName = trim((string) ($cmsUser['email'] ?? 'Member'));
        }

        return [
            'catalog_member' => [
                'cms_user_id' => $cmsUserId,
                'display_name' => $displayName,
                'email' => trim((string) ($cmsUser['email'] ?? '')),
                'submissions' => $rows,
                'recent_submissions' => array_slice($rows, 0, max(1, min(20, $recentLimit))),
                'download_counts' => $downloadCounts,
            ],
        ];
    }

    /**
     * @param array<string, int> $downloadCounts
     */
    public static function downloadCountFor(CatalogSubmission $submission, array $downloadCounts): int
    {
        $key = $submission->kind . ':' . $submission->slug;

        return $downloadCounts[$key] ?? 0;
    }
}
