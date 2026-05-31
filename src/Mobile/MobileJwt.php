<?php

declare(strict_types=1);

namespace App\Mobile;

/**
 * HS256 JWT for mobile access tokens (no external dependency).
 */
final class MobileJwt
{
    private const ALGORITHM = 'HS256';
    public const ACCESS_TTL_SECONDS = 900;

    /**
     * @param array<string, mixed> $payload
     */
    public static function encode(array $payload): string
    {
        $header = ['typ' => 'JWT', 'alg' => self::ALGORITHM];
        $segments = [
            self::base64UrlEncode(json_encode($header, JSON_THROW_ON_ERROR)),
            self::base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR)),
        ];
        $signingInput = implode('.', $segments);
        $signature = self::base64UrlEncode(hash_hmac('sha256', $signingInput, self::signingKey(), true));

        return $signingInput . '.' . $signature;
    }

    /**
     * @return array<string, mixed>
     */
    public static function decode(string $token): array
    {
        $parts = explode('.', trim($token));
        if (count($parts) !== 3) {
            throw new MobileAuthException('invalid_token', 'Invalid access token.', 401);
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;
        $signingInput = $encodedHeader . '.' . $encodedPayload;
        $expected = self::base64UrlEncode(hash_hmac('sha256', $signingInput, self::signingKey(), true));
        if (!hash_equals($expected, $encodedSignature)) {
            throw new MobileAuthException('invalid_token', 'Invalid access token.', 401);
        }

        $payloadJson = self::base64UrlDecode($encodedPayload);
        try {
            $payload = json_decode($payloadJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new MobileAuthException('invalid_token', 'Invalid access token.', 401);
        }

        if (!is_array($payload) || ($payload['typ'] ?? '') !== 'access') {
            throw new MobileAuthException('invalid_token', 'Invalid access token.', 401);
        }

        $exp = (int) ($payload['exp'] ?? 0);
        if ($exp < time()) {
            throw new MobileAuthException('token_expired', 'Access token expired.', 401);
        }

        return $payload;
    }

    /**
     * @return array{token: string, expires_at: int}
     */
    public static function issueAccessToken(int $userId, string $email): array
    {
        $now = time();
        $expiresAt = $now + self::ACCESS_TTL_SECONDS;

        return [
            'token' => self::encode([
                'sub' => (string) $userId,
                'email' => $email,
                'typ' => 'access',
                'iat' => $now,
                'exp' => $expiresAt,
            ]),
            'expires_at' => $expiresAt,
        ];
    }

    private static function signingKey(): string
    {
        $siteKey = $_ENV['PHPAUTH_SITE_KEY'] ?? getenv('PHPAUTH_SITE_KEY');
        $siteKey = is_string($siteKey) ? trim($siteKey) : '';
        if ($siteKey === '') {
            throw new MobileAuthException(
                'auth_not_configured',
                'Mobile auth requires PHPAUTH_SITE_KEY in the environment.',
                503,
            );
        }

        return hash('sha256', $siteKey . '|struxa_mobile_jwt_v1', true);
    }

    private static function base64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $encoded): string
    {
        $remainder = strlen($encoded) % 4;
        if ($remainder > 0) {
            $encoded .= str_repeat('=', 4 - $remainder);
        }
        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);
        if ($decoded === false) {
            throw new MobileAuthException('invalid_token', 'Invalid access token.', 401);
        }

        return $decoded;
    }
}
