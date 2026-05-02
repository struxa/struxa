<?php

declare(strict_types=1);

namespace App\Auth;

final class UsernameValidation
{
    public const MIN_LEN = 3;

    public const MAX_LEN = 32;

    /**
     * @return array{ok: true, value: string}|array{ok: false, message: string}
     *         When optional and empty, value is '' (store as NULL in DB).
     */
    public static function validate(?string $raw, bool $required): array
    {
        $s = trim((string) $raw);
        if ($s === '') {
            return $required
                ? ['ok' => false, 'message' => 'Username is required.']
                : ['ok' => true, 'value' => ''];
        }
        if (strlen($s) < self::MIN_LEN) {
            return ['ok' => false, 'message' => 'Username must be at least ' . self::MIN_LEN . ' characters.'];
        }
        if (strlen($s) > self::MAX_LEN) {
            return ['ok' => false, 'message' => 'Username must be ' . self::MAX_LEN . ' characters or fewer.'];
        }
        if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]*$/', $s) !== 1) {
            return ['ok' => false, 'message' => 'Use letters, numbers, underscores, or hyphens; start with a letter or number.'];
        }

        return ['ok' => true, 'value' => $s];
    }
}
