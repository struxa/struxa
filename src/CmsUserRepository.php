<?php

declare(strict_types=1);

namespace App;

use PDO;

final class CmsUserRepository
{
    /**
     * @return ?array{id: int, phpauth_user_id: int, email: string, display_name: string, role: string, is_active: int, username: ?string, firebase_uid: ?string}
     */
    public static function findByPhpAuthId(PDO $pdo, int $phpauthUserId): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT c.id, c.phpauth_user_id, c.firebase_uid, c.email, c.display_name, c.role, c.is_active, p.username AS username
             FROM cms_users c
             LEFT JOIN phpauth_users p ON p.id = c.phpauth_user_id
             WHERE c.phpauth_user_id = ? LIMIT 1'
        );
        $stmt->execute([$phpauthUserId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * @return ?array<string, mixed>
     */
    public static function findByFirebaseUid(PDO $pdo, string $firebaseUid): ?array
    {
        $firebaseUid = trim($firebaseUid);
        if ($firebaseUid === '') {
            return null;
        }

        try {
            $stmt = $pdo->prepare(
                'SELECT c.id, c.phpauth_user_id, c.firebase_uid, c.email, c.display_name, c.role, c.is_active, p.username AS username
                 FROM cms_users c
                 LEFT JOIN phpauth_users p ON p.id = c.phpauth_user_id
                 WHERE c.firebase_uid = ? LIMIT 1'
            );
            $stmt->execute([$firebaseUid]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\PDOException) {
            return null;
        }

        return $row === false ? null : $row;
    }

    /**
     * If firebase_uid is linked to a different phpauth user, returns that phpauth id; else null.
     */
    public static function firebaseUidOwnerPhpAuthId(PDO $pdo, string $firebaseUid, int $exceptPhpauthId): ?int
    {
        $row = self::findByFirebaseUid($pdo, $firebaseUid);
        if ($row === null) {
            return null;
        }
        $owner = (int) ($row['phpauth_user_id'] ?? 0);
        if ($owner < 1 || $owner === $exceptPhpauthId) {
            return null;
        }

        return $owner;
    }

    /**
     * Ensure a cms_users member row exists and store the Firebase UID.
     *
     * @return int cms_users.id
     */
    public static function ensureMemberWithFirebase(
        PDO $pdo,
        int $phpauthUserId,
        string $email,
        string $displayName,
        string $firebaseUid,
    ): int {
        $existing = self::findByPhpAuthId($pdo, $phpauthUserId);
        if ($existing !== null) {
            $cmsId = (int) $existing['id'];
            self::setFirebaseUid($pdo, $cmsId, $firebaseUid);

            return $cmsId;
        }

        $cmsId = self::insert($pdo, $phpauthUserId, $email, $displayName);
        self::setFirebaseUid($pdo, $cmsId, $firebaseUid);

        return $cmsId;
    }

    public static function setFirebaseUid(PDO $pdo, int $cmsUserId, ?string $firebaseUid): void
    {
        $firebaseUid = $firebaseUid !== null ? trim($firebaseUid) : null;
        if ($firebaseUid === '') {
            $firebaseUid = null;
        }

        $stmt = $pdo->prepare('UPDATE cms_users SET firebase_uid = ? WHERE id = ?');
        $stmt->execute([$firebaseUid, $cmsUserId]);
    }

    /**
     * @return ?array<string, mixed>
     */
    public static function findById(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT c.id, c.phpauth_user_id, c.firebase_uid, c.email, c.display_name, c.role, c.is_active, c.created_at, c.updated_at,
                    p.isactive AS phpauth_isactive, p.username AS username
             FROM cms_users c
             LEFT JOIN phpauth_users p ON p.id = c.phpauth_user_id
             WHERE c.id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function allOrdered(PDO $pdo): array
    {
        $sql = 'SELECT c.id, c.phpauth_user_id, c.firebase_uid, c.email, c.display_name, c.role, c.is_active, c.created_at,
                       p.isactive AS phpauth_isactive, p.username AS username
                FROM cms_users c
                LEFT JOIN phpauth_users p ON p.id = c.phpauth_user_id
                ORDER BY c.email ASC';
        $stmt = $pdo->query($sql);
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[] = $row;
        }

        return $out;
    }

    public static function countAll(PDO $pdo): int
    {
        return (int) $pdo->query('SELECT COUNT(*) FROM cms_users')->fetchColumn();
    }

    /**
     * Paginated list (same columns as {@see allOrdered}).
     *
     * @return list<array<string, mixed>>
     */
    public static function listOrderedPage(PDO $pdo, int $limit, int $offset): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $sql = 'SELECT c.id, c.phpauth_user_id, c.firebase_uid, c.email, c.display_name, c.role, c.is_active, c.created_at,
                       p.isactive AS phpauth_isactive, p.username AS username
                FROM cms_users c
                LEFT JOIN phpauth_users p ON p.id = c.phpauth_user_id
                ORDER BY c.email ASC
                LIMIT :lim OFFSET :off';
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[] = $row;
        }

        return $out;
    }

    /**
     * True if another phpauth user already has this email.
     */
    public static function phpauthEmailTakenByOther(PDO $pdo, string $email, int $excludePhpauthId): bool
    {
        $stmt = $pdo->prepare('SELECT 1 FROM phpauth_users WHERE LOWER(TRIM(email)) = LOWER(TRIM(?)) AND id != ? LIMIT 1');
        $stmt->execute([$email, $excludePhpauthId]);

        return (bool) $stmt->fetchColumn();
    }

    public static function updateEmail(PDO $pdo, int $cmsId, int $phpauthId, string $email): void
    {
        $stmt = $pdo->prepare('UPDATE cms_users SET email = ? WHERE id = ?');
        $stmt->execute([$email, $cmsId]);
        $stmt = $pdo->prepare('UPDATE phpauth_users SET email = ? WHERE id = ?');
        $stmt->execute([$email, $phpauthId]);
    }

    public static function updatePhpAuthPasswordHash(PDO $pdo, int $phpauthId, string $passwordHash): void
    {
        $stmt = $pdo->prepare('UPDATE phpauth_users SET password = ? WHERE id = ?');
        $stmt->execute([$passwordHash, $phpauthId]);
    }

    /**
     * @return int new cms_users.id
     */
    public static function insert(PDO $pdo, int $phpauthUserId, string $email, string $displayName): int
    {
        $stmt = $pdo->prepare(
            'INSERT INTO cms_users (phpauth_user_id, email, display_name, role, is_active) VALUES (?, ?, ?, \'subscriber\', 1)'
        );
        $stmt->execute([$phpauthUserId, $email, $displayName]);

        $newId = (int) $pdo->lastInsertId();
        $memberRole = $pdo->query("SELECT id FROM cms_roles WHERE slug = 'member' LIMIT 1");
        if ($memberRole !== false) {
            $roleId = $memberRole->fetchColumn();
            if ($roleId !== false) {
                $pdo->prepare('INSERT IGNORE INTO cms_role_user (role_id, user_id) VALUES (?, ?)')
                    ->execute([(int) $roleId, $newId]);
            }
        }

        return $newId;
    }

    public static function updateProfile(PDO $pdo, int $id, string $displayName): void
    {
        $stmt = $pdo->prepare('UPDATE cms_users SET display_name = ? WHERE id = ?');
        $stmt->execute([$displayName, $id]);
    }

    public static function setCmsActive(PDO $pdo, int $id, bool $active): void
    {
        $stmt = $pdo->prepare('UPDATE cms_users SET is_active = ? WHERE id = ?');
        $stmt->execute([$active ? 1 : 0, $id]);
    }

    public static function setPhpAuthActive(PDO $pdo, int $phpauthUserId, bool $active): void
    {
        $stmt = $pdo->prepare('UPDATE phpauth_users SET isactive = ? WHERE id = ?');
        $stmt->execute([$active ? 1 : 0, $phpauthUserId]);
    }

    public static function tableExists(PDO $pdo): bool
    {
        try {
            $pdo->query('SELECT 1 FROM cms_users LIMIT 1');

            return true;
        } catch (\PDOException) {
            return false;
        }
    }

    /**
     * @return ?array{id: int, email: string, totp_secret: ?string, totp_enabled: int}
     */
    public static function findTotpStateByPhpAuthId(PDO $pdo, int $phpauthUserId): ?array
    {
        try {
            $stmt = $pdo->prepare(
                'SELECT id, email, totp_secret, totp_enabled FROM cms_users WHERE phpauth_user_id = ? LIMIT 1'
            );
            $stmt->execute([$phpauthUserId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\PDOException) {
            return null;
        }

        return $row === false ? null : $row;
    }

    public static function updateTotpSecret(PDO $pdo, int $cmsUserId, ?string $secret): void
    {
        $stmt = $pdo->prepare('UPDATE cms_users SET totp_secret = ? WHERE id = ?');
        $stmt->execute([$secret, $cmsUserId]);
    }

    public static function setTotpEnabled(PDO $pdo, int $cmsUserId, bool $enabled): void
    {
        $stmt = $pdo->prepare('UPDATE cms_users SET totp_enabled = ? WHERE id = ?');
        $stmt->execute([$enabled ? 1 : 0, $cmsUserId]);
    }
}
