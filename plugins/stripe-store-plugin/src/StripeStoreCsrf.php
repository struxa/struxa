<?php

declare(strict_types=1);

namespace StripeStorePlugin;

use App\Flash;

final class StripeStoreCsrf
{
    private const SESSION_KEY = '_stripe_store_csrf';

    public static function token(): string
    {
        Flash::start();
        if (!isset($_SESSION[self::SESSION_KEY]) || !is_string($_SESSION[self::SESSION_KEY]) || strlen($_SESSION[self::SESSION_KEY]) < 32) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::SESSION_KEY];
    }

    public static function validate(?string $submitted): bool
    {
        Flash::start();
        $expected = $_SESSION[self::SESSION_KEY] ?? '';
        if (!is_string($expected) || $expected === '' || !is_string($submitted)) {
            return false;
        }

        return hash_equals($expected, $submitted);
    }
}
