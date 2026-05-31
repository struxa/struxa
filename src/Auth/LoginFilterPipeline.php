<?php

declare(strict_types=1);

namespace App\Auth;

use App\Filter\FilterHook;
use App\Filter\Filters;

/**
 * Applies {@see FilterHook::USER_LOGIN} before a CMS session is established.
 */
final class LoginFilterPipeline
{
    /**
     * @return string|null Block message when login must be denied; null to continue.
     */
    public static function blockMessage(string $email, int $userId, string $method): ?string
    {
        $payload = Filters::apply(FilterHook::USER_LOGIN, [
            'email' => $email,
            'user_id' => $userId,
            'method' => $method,
            'allowed' => true,
        ], []);

        if (!is_array($payload) || ($payload['allowed'] ?? true) !== false) {
            return null;
        }
        $msg = $payload['block_message'] ?? null;

        return is_string($msg) && trim($msg) !== '' ? trim($msg) : 'Login not permitted.';
    }
}
