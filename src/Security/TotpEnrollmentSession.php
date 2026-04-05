<?php

declare(strict_types=1);

namespace App\Security;

use App\Flash;

/**
 * Pending TOTP secret during admin enrollment (before first successful code confirms).
 */
final class TotpEnrollmentSession
{
    private const KEY = '_cms_totp_enroll';

    private const TTL_SEC = 1800;

    /**
     * @return array{cms_user_id: int, secret: string}|null
     */
    public static function get(): ?array
    {
        Flash::start();
        $p = $_SESSION[self::KEY] ?? null;
        if (!is_array($p)) {
            return null;
        }
        if ((int) ($p['exp'] ?? 0) < time()) {
            unset($_SESSION[self::KEY]);

            return null;
        }
        $cid = (int) ($p['cms_user_id'] ?? 0);
        $secret = (string) ($p['secret'] ?? '');
        if ($cid < 1 || $secret === '') {
            return null;
        }

        return ['cms_user_id' => $cid, 'secret' => $secret];
    }

    public static function put(int $cmsUserId, string $secret): void
    {
        Flash::start();
        $_SESSION[self::KEY] = [
            'cms_user_id' => $cmsUserId,
            'secret' => $secret,
            'exp' => time() + self::TTL_SEC,
        ];
    }

    public static function clear(): void
    {
        Flash::start();
        unset($_SESSION[self::KEY]);
    }
}
