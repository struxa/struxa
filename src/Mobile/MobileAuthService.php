<?php

declare(strict_types=1);

namespace App\Mobile;

use App\Auth\AppAuth;
use App\Auth\LoginFilterPipeline;
use App\Auth\PhpAuthUsernameRepository;
use App\Auth\UsernameValidation;
use App\CmsUserRepository;
use App\Security\TotpService;
use App\Settings;
use PDO;

final class MobileAuthService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AppAuth $auth,
        private readonly ?MobileRefreshTokenRepository $refreshTokens = null,
    ) {
    }

    private function refreshRepo(): MobileRefreshTokenRepository
    {
        return $this->refreshTokens ?? new MobileRefreshTokenRepository($this->pdo);
    }

    /**
     * @return array<string, mixed>
     */
    public function login(string $email, string $password, string $totpCode = ''): array
    {
        $this->assertEnabled();

        $email = trim($email);
        $password = (string) $password;
        if ($email === '' || $password === '') {
            throw new MobileAuthException('validation_error', 'Email and password are required.');
        }

        $result = $this->auth->verifyPasswordPreSession($email, $password, 0);
        if (($result['error'] ?? true) === true) {
            throw new MobileAuthException('invalid_credentials', (string) ($result['message'] ?? 'Invalid credentials.'), 401);
        }

        $uid = (int) ($result['uid'] ?? 0);
        if ($uid < 1) {
            throw new MobileAuthException('invalid_credentials', 'Invalid credentials.', 401);
        }

        $this->assertTotpIfRequired($uid, $totpCode);

        $block = LoginFilterPipeline::blockMessage($email, $uid, 'mobile_password');
        if ($block !== null) {
            throw new MobileAuthException('login_blocked', $block, 403);
        }

        return $this->issueAuthResponse($uid);
    }

    /**
     * @return array<string, mixed>
     */
    public function register(
        string $email,
        string $password,
        string $passwordConfirm,
        string $usernameRaw = '',
    ): array {
        $this->assertEnabled();

        $email = trim($email);
        if ($email === '' || $password === '') {
            throw new MobileAuthException('validation_error', 'Email and password are required.');
        }

        $collectUsername = Settings::get('registration_collect_username', '0') === '1';
        $usernameCheck = UsernameValidation::validate($usernameRaw, $collectUsername);
        if (!$usernameCheck['ok']) {
            throw new MobileAuthException('validation_error', $usernameCheck['message']);
        }
        if ($collectUsername && $usernameCheck['value'] !== '' && PhpAuthUsernameRepository::isTaken($this->pdo, $usernameCheck['value'])) {
            throw new MobileAuthException('validation_error', 'That username is already taken.');
        }

        $result = $this->auth->register($email, $password, $passwordConfirm, [], '', false);
        if (($result['error'] ?? true) === true) {
            throw new MobileAuthException('registration_failed', (string) ($result['message'] ?? 'Registration failed.'));
        }

        $uid = (int) ($result['uid'] ?? 0);
        if ($uid < 1) {
            throw new MobileAuthException('registration_failed', 'Registration failed.');
        }

        if ($collectUsername && $usernameCheck['value'] !== '') {
            PhpAuthUsernameRepository::setForUserId($this->pdo, $uid, $usernameCheck['value']);
        }

        $profile = $this->userProfile($uid);
        if ($profile === null) {
            throw new MobileAuthException('registration_failed', 'Registration failed.');
        }

        if (!$profile['is_active']) {
            return [
                'activated' => false,
                'message' => (string) ($result['message'] ?? 'Account created. Check your email if activation is required.'),
                'user' => $profile,
            ];
        }

        return ['activated' => true] + $this->issueAuthResponse($uid);
    }

    /**
     * @return array<string, mixed>
     */
    public function refresh(string $refreshToken): array
    {
        $this->assertEnabled();

        $row = $this->refreshRepo()->findActiveByPlainToken($refreshToken);
        if ($row === null) {
            throw new MobileAuthException('invalid_refresh_token', 'Refresh token is invalid or expired.', 401);
        }

        $uid = (int) $row['phpauth_user_id'];
        $profile = $this->userProfile($uid);
        if ($profile === null || !$profile['is_active']) {
            throw new MobileAuthException('invalid_refresh_token', 'Account is not available.', 401);
        }

        $this->refreshRepo()->revokeById((int) $row['id']);

        return $this->issueAuthResponse($uid);
    }

    public function logout(string $refreshToken): void
    {
        $this->assertEnabled();
        $this->refreshRepo()->revokeByPlainToken($refreshToken);
    }

    /**
     * @return array<string, mixed>
     */
    public function me(int $userId): array
    {
        $this->assertEnabled();
        $profile = $this->userProfile($userId);
        if ($profile === null) {
            throw new MobileAuthException('not_found', 'User not found.', 404);
        }

        return ['user' => $profile];
    }

    /**
     * @return array{userId: int, email: string}
     */
    public function authenticateAccessToken(string $authorizationHeader): array
    {
        $this->assertEnabled();
        $token = self::extractBearerToken($authorizationHeader);
        $payload = MobileJwt::decode($token);
        $uid = (int) ($payload['sub'] ?? 0);
        $email = trim((string) ($payload['email'] ?? ''));
        if ($uid < 1 || $email === '') {
            throw new MobileAuthException('invalid_token', 'Invalid access token.', 401);
        }

        return ['userId' => $uid, 'email' => $email];
    }

    private static function extractBearerToken(string $header): string
    {
        if (preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $header, $m) !== 1) {
            throw new MobileAuthException('unauthorized', 'Missing Bearer access token.', 401);
        }

        return $m[1];
    }

    /**
     * @return array<string, mixed>
     */
    private function issueAuthResponse(int $uid): array
    {
        $profile = $this->userProfile($uid);
        if ($profile === null) {
            throw new MobileAuthException('not_found', 'User not found.', 404);
        }
        if (!$profile['is_active']) {
            throw new MobileAuthException('account_inactive', 'Account is not activated.', 403);
        }

        $access = MobileJwt::issueAccessToken($uid, $profile['email']);
        $refresh = $this->refreshRepo()->create($uid);

        return [
            'access_token' => $access['token'],
            'refresh_token' => $refresh['token'],
            'expires_in' => MobileJwt::ACCESS_TTL_SECONDS,
            'expires_at' => $access['expires_at'],
            'token_type' => 'Bearer',
            'user' => $profile,
        ];
    }

    /**
     * @return array{id: int, email: string, username: ?string, display_name: ?string, is_active: bool, is_cms_staff: bool}|null
     */
    private function userProfile(int $uid): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, email, username, isactive FROM phpauth_users WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$uid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        $cms = CmsUserRepository::findByPhpAuthId($this->pdo, $uid);
        $username = isset($row['username']) && $row['username'] !== '' ? (string) $row['username'] : null;
        $displayName = $cms !== null ? trim((string) ($cms['display_name'] ?? '')) : '';

        return [
            'id' => (int) $row['id'],
            'email' => (string) $row['email'],
            'username' => $username,
            'display_name' => $displayName !== '' ? $displayName : null,
            'is_active' => (int) ($row['isactive'] ?? 0) === 1,
            'is_cms_staff' => $cms !== null && (int) ($cms['is_active'] ?? 0) === 1,
        ];
    }

    private function assertTotpIfRequired(int $uid, string $totpCode): void
    {
        $totpRow = CmsUserRepository::findTotpStateByPhpAuthId($this->pdo, $uid);
        $needsTotp = $totpRow !== null
            && (int) ($totpRow['totp_enabled'] ?? 0) === 1
            && trim((string) ($totpRow['totp_secret'] ?? '')) !== '';

        if (!$needsTotp) {
            return;
        }

        $totpCode = preg_replace('/\s+/', '', trim($totpCode)) ?? '';
        if ($totpCode === '') {
            throw new MobileAuthException(
                'totp_required',
                'Two-factor authentication code required.',
                403,
            );
        }

        $issuer = trim(Settings::get('site_name', '') ?: 'Struxa');
        $totp = new TotpService($issuer);
        if (!$totp->verify(trim((string) ($totpRow['totp_secret'] ?? '')), $totpCode)) {
            throw new MobileAuthException('invalid_totp', 'Invalid two-factor code.', 401);
        }
    }

    private function assertEnabled(): void
    {
        if (!MobileSettings::enabled()) {
            throw new MobileAuthException(
                'mobile_disabled',
                'Mobile app access is disabled for this site.',
                403,
            );
        }
    }
}
