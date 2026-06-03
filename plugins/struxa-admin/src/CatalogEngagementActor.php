<?php

declare(strict_types=1);

namespace StruxaAdmin;

use App\CmsUserRepository;

/**
 * Resolve CMS user id + display label for catalog engagement actions.
 */
final class CatalogEngagementActor
{
    /**
     * @param array<string, mixed> $viewData
     *
     * @return array{ok: true, cms_user_id: int, display_name: string}|array{ok: false}
     */
    public static function fromView(\PDO $pdo, array $viewData): array
    {
        if (empty($viewData['logged_in'])) {
            return ['ok' => false];
        }

        $phpauthId = isset($viewData['phpauth_user_id']) ? (int) $viewData['phpauth_user_id'] : 0;
        if ($phpauthId <= 0 || !CmsUserRepository::tableExists($pdo)) {
            return ['ok' => false];
        }

        $cmsUser = CmsUserRepository::findByPhpAuthId($pdo, $phpauthId);
        if ($cmsUser === null || (int) ($cmsUser['is_active'] ?? 0) !== 1) {
            return ['ok' => false];
        }

        $displayName = trim((string) ($viewData['user_display_name'] ?? ''));
        if ($displayName === '') {
            $displayName = trim((string) ($cmsUser['display_name'] ?? ''));
        }
        if ($displayName === '') {
            $displayName = trim((string) ($cmsUser['username'] ?? ''));
        }
        if ($displayName === '') {
            $displayName = trim((string) ($cmsUser['email'] ?? 'Member'));
        }

        return [
            'ok' => true,
            'cms_user_id' => (int) $cmsUser['id'],
            'display_name' => $displayName,
        ];
    }

    /**
     * @param list<array{cms_user_id: int, rating: int, body: string, created_at: string, updated_at: string}> $reviews
     *
     * @return list<array{rating: int, body: string, created_at: string, updated_at: string, author_name: string, cms_user_id: int}>
     */
    public static function decorateReviews(\PDO $pdo, array $reviews, ?int $viewerUserId = null): array
    {
        $out = [];
        foreach ($reviews as $review) {
            $userId = (int) ($review['cms_user_id'] ?? 0);
            $author = 'Member';
            if ($userId > 0) {
                $user = CmsUserRepository::findById($pdo, $userId);
                if ($user !== null) {
                    $name = trim((string) ($user['display_name'] ?? ''));
                    if ($name === '') {
                        $name = trim((string) ($user['username'] ?? ''));
                    }
                    if ($name === '') {
                        $name = trim((string) ($user['email'] ?? 'Member'));
                    }
                    $author = $name !== '' ? $name : 'Member';
                }
            }
            $out[] = [
                'cms_user_id' => $userId,
                'rating' => (int) ($review['rating'] ?? 0),
                'body' => (string) ($review['body'] ?? ''),
                'created_at' => (string) ($review['created_at'] ?? ''),
                'updated_at' => (string) ($review['updated_at'] ?? ''),
                'author_name' => $author,
                'is_own' => $viewerUserId !== null && $viewerUserId > 0 && $userId === $viewerUserId,
            ];
        }

        return $out;
    }
}
