<?php

declare(strict_types=1);

namespace MailingListPlugin;

final class EmailValidator
{
    private const MAX_LEN = 190;

    /**
     * @return array{ok: true, email: string}|array{ok: false, error: string}
     */
    public static function normalizeAndValidate(string $raw): array
    {
        $email = strtolower(trim(str_replace("\0", '', $raw)));
        if ($email === '') {
            return ['ok' => false, 'error' => 'Email is required.'];
        }
        if (strlen($email) > self::MAX_LEN) {
            return ['ok' => false, 'error' => 'Email is too long.'];
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return ['ok' => false, 'error' => 'Enter a valid email address.'];
        }

        return ['ok' => true, 'email' => $email];
    }
}
