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
     * @param list<array{cms_user_id: int, body: string, created_at: string, id: int}> $comments
     *
     * @return list<array{id: int, body: string, created_at: string, author_name: string}>
     */
    public static function decorateComments(\PDO $pdo, array $comments): array
    {
        $out = [];
        foreach ($comments as $comment) {
            $userId = (int) ($comment['cms_user_id'] ?? 0);
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
                'id' => (int) ($comment['id'] ?? 0),
                'body' => (string) ($comment['body'] ?? ''),
                'created_at' => (string) ($comment['created_at'] ?? ''),
                'author_name' => $author,
            ];
        }

        return $out;
    }
}
