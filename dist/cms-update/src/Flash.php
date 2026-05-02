<?php

declare(strict_types=1);

namespace App;

final class Flash
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    public static function set(string $key, string $message): void
    {
        self::start();
        $_SESSION['_flash'][$key] = $message;
    }

    public static function pull(string $key): ?string
    {
        self::start();
        $message = $_SESSION['_flash'][$key] ?? null;
        unset($_SESSION['_flash'][$key]);

        return is_string($message) ? $message : null;
    }

    /**
     * True when flash messages are waiting (do not cache public HTML — content may embed them).
     */
    public static function hasPending(): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }
        $f = $_SESSION['_flash'] ?? null;

        return is_array($f) && $f !== [];
    }
}
