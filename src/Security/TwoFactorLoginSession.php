<?php

declare(strict_types=1);

namespace App\Security;

use App\Flash;

/**
 * Pending PHPAuth UID after password OK, before TOTP (public /login flow).
 */
final class TwoFactorLoginSession
{
    private const KEY = '_cms_totp_login_pending';

    private const TTL_SEC = 600;

    /**
     * @return array{phpauth_uid: int, remember: int}|null
     */
    public static function get(): ?array
    {
        Flash::start();
        $p = $_SESSION[self::KEY] ?? null;
        if (!is_array($p)) {
            return null;
        }
        $exp = (int) ($p['exp'] ?? 0);
        if ($exp < time()) {
            unset($_SESSION[self::KEY]);

            return null;
        }
        $uid = (int) ($p['phpauth_uid'] ?? 0);
        if ($uid < 1) {
            return null;
        }

        return [
            'phpauth_uid' => $uid,
            'remember' => (int) ($p['remember'] ?? 0) === 1 ? 1 : 0,
        ];
    }

    public static function put(int $phpauthUid, int $remember): void
    {
        Flash::start();
        $_SESSION[self::KEY] = [
            'phpauth_uid' => $phpauthUid,
            'remember' => $remember === 1 ? 1 : 0,
            'exp' => time() + self::TTL_SEC,
        ];
    }

    public static function clear(): void
    {
        Flash::start();
        unset($_SESSION[self::KEY]);
    }

    /**
     * Whether a pending 2FA login gate is active (session-sensitive; do not cache public pages).
     */
    public static function isPending(): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }
        $p = $_SESSION[self::KEY] ?? null;
        if (!is_array($p)) {
            return false;
        }
        $exp = (int) ($p['exp'] ?? 0);
        if ($exp < time()) {
            unset($_SESSION[self::KEY]);

            return false;
        }
        $uid = (int) ($p['phpauth_uid'] ?? 0);

        return $uid > 0;
    }
}
