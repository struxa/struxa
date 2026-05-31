<?php

declare(strict_types=1);

namespace App\Privacy;

/**
 * Matches comment storage: sha256(lower(trim(email))).
 */
final class PrivacyEmailHasher
{
    public static function normalize(string $email): string
    {
        return trim(strtolower($email));
    }

    public static function hash(string $email): string
    {
        return hash('sha256', self::normalize($email));
    }

    public static function isValidEmail(string $email): bool
    {
        $normalized = self::normalize($email);

        return $normalized !== ''
            && filter_var($normalized, FILTER_VALIDATE_EMAIL) !== false
            && strlen($normalized) <= 190;
    }
}
