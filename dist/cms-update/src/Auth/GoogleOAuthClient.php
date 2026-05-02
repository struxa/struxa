<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * Minimal Google OAuth2 authorization-code + OpenID userinfo (no extra Composer deps).
 */
final class GoogleOAuthClient
{
    public function __construct(
        private readonly GoogleSsoConfig $config,
    ) {
    }

    public function authorizationUrl(string $state): string
    {
        $query = http_build_query([
            'client_id' => $this->config->clientId,
            'redirect_uri' => $this->config->redirectUri,
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'prompt' => 'select_account',
        ], '', '&', PHP_QUERY_RFC3986);

        return 'https://accounts.google.com/o/oauth2/v2/auth?' . $query;
    }

    /**
     * @return array{access_token: string}
     *
     * @throws \RuntimeException on transport or OAuth error
     */
    public function exchangeAuthorizationCode(string $code): array
    {
        $body = http_build_query([
            'code' => $code,
            'client_id' => $this->config->clientId,
            'client_secret' => $this->config->clientSecret,
            'redirect_uri' => $this->config->redirectUri,
            'grant_type' => 'authorization_code',
        ], '', '&', PHP_QUERY_RFC3986);

        $raw = $this->httpPostForm('https://oauth2.googleapis.com/token', $body);
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            throw new \RuntimeException('Invalid token response from Google.');
        }
        if (isset($json['error'])) {
            $desc = is_string($json['error_description'] ?? null) ? $json['error_description'] : '';
            throw new \RuntimeException(trim('Google token error: ' . (string) $json['error'] . ' ' . $desc));
        }
        $token = $json['access_token'] ?? null;
        if (!is_string($token) || $token === '') {
            throw new \RuntimeException('Google token response missing access_token.');
        }

        return ['access_token' => $token];
    }

    /**
     * @return array{email: string, email_verified: bool, name: string}
     *
     * @throws \RuntimeException
     */
    public function fetchUserInfo(string $accessToken): array
    {
        $raw = $this->httpGet(
            'https://openidconnect.googleapis.com/v1/userinfo',
            ['Authorization: Bearer ' . $accessToken]
        );
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            throw new \RuntimeException('Invalid userinfo response from Google.');
        }
        $email = isset($json['email']) ? trim((string) $json['email']) : '';
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('Google did not return a valid email.');
        }
        $ev = $json['email_verified'] ?? false;
        $verified = $ev === true || $ev === 1 || $ev === '1' || strtolower((string) $ev) === 'true';
        $name = isset($json['name']) ? trim((string) $json['name']) : '';
        if ($name === '') {
            $name = $email;
        }

        return [
            'email' => $email,
            'email_verified' => $verified,
            'name' => $name,
        ];
    }

    private function httpPostForm(string $url, string $body): string
    {
        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => $body,
                'timeout' => 25,
                'ignore_errors' => true,
            ],
        ]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) {
            throw new \RuntimeException('Could not reach Google token endpoint.');
        }

        return $raw;
    }

    /**
     * @param list<string> $extraHeaders
     */
    private function httpGet(string $url, array $extraHeaders): string
    {
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $extraHeaders) . "\r\n",
                'timeout' => 20,
                'ignore_errors' => true,
            ],
        ]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) {
            throw new \RuntimeException('Could not reach Google userinfo endpoint.');
        }

        return $raw;
    }
}
