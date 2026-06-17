<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * Validates Firebase ID tokens via Identity Toolkit REST (accounts:lookup).
 */
final class FirebaseIdTokenVerifier
{
    public function __construct(
        private readonly FirebaseConfig $config,
    ) {
    }

    /**
     * @return ?array{
     *   local_id: string,
     *   email: string,
     *   email_verified: bool,
     *   display_name: string
     * }
     */
    public function verify(string $idToken): ?array
    {
        $idToken = trim($idToken);
        if ($idToken === '' || strlen($idToken) > 8192) {
            return null;
        }

        $url = 'https://identitytoolkit.googleapis.com/v1/accounts:lookup?key=' . rawurlencode($this->config->apiKey);
        $payload = json_encode(['idToken' => $idToken], JSON_THROW_ON_ERROR);

        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => $payload,
                'timeout' => 12,
                'ignore_errors' => true,
            ],
        ]);

        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false || $raw === '') {
            return null;
        }

        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!isset($data['users']) || !is_array($data['users']) || $data['users'] === []) {
            return null;
        }

        $user = $data['users'][0];
        if (!is_array($user)) {
            return null;
        }

        $localId = trim((string) ($user['localId'] ?? ''));
        $email = strtolower(trim((string) ($user['email'] ?? '')));
        if ($localId === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        $emailVerified = filter_var($user['emailVerified'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $displayName = trim((string) ($user['displayName'] ?? ''));

        return [
            'local_id' => $localId,
            'email' => $email,
            'email_verified' => $emailVerified,
            'display_name' => $displayName,
        ];
    }
}
