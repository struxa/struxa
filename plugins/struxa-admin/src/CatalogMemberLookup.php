<?php

declare(strict_types=1);

namespace StruxaAdmin;

use App\CmsUserRepository;
use PDO;

/**
 * Resolve CMS members by username for catalog submission admin edits.
 */
final class CatalogMemberLookup
{
    /**
     * @return list<array{username: string, display_name: string, email: string, cms_user_id: int}>
     */
    public static function search(PDO $pdo, string $query, int $limit = 12): array
    {
        if (!CmsUserRepository::tableExists($pdo)) {
            return [];
        }
        $query = trim($query);
        if ($query === '' || mb_strlen($query) < 2) {
            return [];
        }
        $limit = max(1, min(20, $limit));
        $like = str_replace(['%', '_'], '', $query) . '%';
        $stmt = $pdo->prepare(
            'SELECT c.id, c.email, c.display_name, p.username AS username
             FROM cms_users c
             LEFT JOIN phpauth_users p ON p.id = c.phpauth_user_id
             WHERE c.is_active = 1
               AND (
                    p.username LIKE ?
                 OR c.email LIKE ?
                 OR c.display_name LIKE ?
               )
             ORDER BY p.username ASC, c.email ASC
             LIMIT ' . $limit
        );
        $stmt->execute([$like, $like, $like]);

        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!is_array($row)) {
                continue;
            }
            $username = trim((string) ($row['username'] ?? ''));
            if ($username === '') {
                continue;
            }
            $displayName = trim((string) ($row['display_name'] ?? ''));
            if ($displayName === '') {
                $displayName = $username;
            }
            $out[] = [
                'username' => $username,
                'display_name' => $displayName,
                'email' => trim((string) ($row['email'] ?? '')),
                'cms_user_id' => (int) ($row['id'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * @return array{ok: true, cms_user_id: int, username: string, email: string, display_name: string}|array{ok: false, error: string}
     */
    public static function resolveUsername(PDO $pdo, string $username): array
    {
        if (!CmsUserRepository::tableExists($pdo)) {
            return ['ok' => false, 'error' => 'Member accounts are not available on this site.'];
        }
        $username = trim($username);
        if ($username === '') {
            return ['ok' => false, 'error' => 'Username is required.'];
        }

        $stmt = $pdo->prepare(
            'SELECT c.id, c.email, c.display_name, c.is_active, p.username AS username
             FROM cms_users c
             INNER JOIN phpauth_users p ON p.id = c.phpauth_user_id
             WHERE LOWER(TRIM(p.username)) = LOWER(TRIM(?))
             LIMIT 1'
        );
        $stmt->execute([$username]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return ['ok' => false, 'error' => 'No active member found with username "' . $username . '".'];
        }
        if ((int) ($row['is_active'] ?? 0) !== 1) {
            return ['ok' => false, 'error' => 'That member account is not active.'];
        }

        $displayName = trim((string) ($row['display_name'] ?? ''));
        if ($displayName === '') {
            $displayName = trim((string) ($row['username'] ?? $username));
        }

        return [
            'ok' => true,
            'cms_user_id' => (int) ($row['id'] ?? 0),
            'username' => trim((string) ($row['username'] ?? $username)),
            'email' => trim((string) ($row['email'] ?? '')),
            'display_name' => $displayName,
        ];
    }
}
