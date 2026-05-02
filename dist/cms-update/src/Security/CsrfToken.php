<?php

declare(strict_types=1);

namespace App\Security;

use App\Flash;

/**
 * Session-backed CSRF token for cookie-authenticated form posts.
 */
final class CsrfToken
{
    public const SESSION_KEY = '_csrf_token';

    public static function startSession(): void
    {
        Flash::start();
    }

    public static function getOrCreate(): string
    {
        self::startSession();
        if (empty($_SESSION[self::SESSION_KEY]) || !is_string($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::SESSION_KEY];
    }

    public static function validate(?string $submitted): bool
    {
        self::startSession();
        $expected = $_SESSION[self::SESSION_KEY] ?? '';
        if (!is_string($expected) || $expected === '') {
            return false;
        }
        if ($submitted === null || $submitted === '') {
            return false;
        }

        return hash_equals($expected, $submitted);
    }
}
