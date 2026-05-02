<?php

declare(strict_types=1);

namespace App\Auth;

use PDO;

final class PhpAuthUsernameRepository
{
    public static function findByUserId(PDO $pdo, int $phpauthUserId): ?string
    {
        $stmt = $pdo->prepare('SELECT username FROM phpauth_users WHERE id = ? LIMIT 1');
        $stmt->execute([$phpauthUserId]);
        $v = $stmt->fetchColumn();
        if ($v === false || $v === null) {
            return null;
        }
        $s = trim((string) $v);

        return $s === '' ? null : $s;
    }

    public static function isTaken(PDO $pdo, string $username, ?int $exceptPhpauthUserId = null): bool
    {
        if ($exceptPhpauthUserId !== null) {
            $stmt = $pdo->prepare('SELECT 1 FROM phpauth_users WHERE username = ? AND id != ? LIMIT 1');
            $stmt->execute([$username, $exceptPhpauthUserId]);
        } else {
            $stmt = $pdo->prepare('SELECT 1 FROM phpauth_users WHERE username = ? LIMIT 1');
            $stmt->execute([$username]);
        }

        return (bool) $stmt->fetchColumn();
    }

    public static function setForUserId(PDO $pdo, int $phpauthUserId, ?string $username): void
    {
        $v = $username !== null && $username !== '' ? $username : null;
        $stmt = $pdo->prepare('UPDATE phpauth_users SET username = ? WHERE id = ?');
        $stmt->execute([$v, $phpauthUserId]);
    }
}
