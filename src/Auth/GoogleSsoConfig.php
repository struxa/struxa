<?php

declare(strict_types=1);

namespace App\Auth;

use App\Settings;
use App\Settings\SiteUrlResolver;

/**
 * Optional Google OAuth2 sign-in. Configured in Admin → Site settings (cms_settings), not .env.
 */
final class GoogleSsoConfig
{
    /**
     * @param list<string> $allowedEmailDomains Lowercase hostnames (no @). Empty = any domain.
     */
    public function __construct(
        public readonly string $clientId,
        public readonly string $clientSecret,
        public readonly string $redirectUri,
        public readonly array $allowedEmailDomains,
        public readonly bool $autoProvision,
    ) {
    }

    public static function fromSettings(): ?self
    {
        if (trim((string) (Settings::get('google_sso_enabled', '0') ?? '0')) !== '1') {
            return null;
        }

        $clientId = trim((string) (Settings::get('google_oauth_client_id', '') ?? ''));
        $clientSecret = trim((string) (Settings::get('google_oauth_client_secret', '') ?? ''));
        if ($clientId === '' || $clientSecret === '') {
            return null;
        }

        $site = SiteUrlResolver::resolve();
        $redirect = trim((string) (Settings::get('google_oauth_redirect_uri', '') ?? ''));
        if ($redirect === '') {
            $redirect = $site . '/auth/google/callback';
        }

        $domainsRaw = trim((string) (Settings::get('google_sso_allowed_domains', '') ?? ''));
        $domains = [];
        if ($domainsRaw !== '') {
            foreach (explode(',', $domainsRaw) as $part) {
                $d = strtolower(trim(str_replace('@', '', $part)));
                if ($d !== '') {
                    $domains[] = $d;
                }
            }
        }

        $autoProvision = trim((string) (Settings::get('google_sso_auto_provision', '0') ?? '0')) === '1';

        return new self($clientId, $clientSecret, $redirect, $domains, $autoProvision);
    }

    public function emailDomainAllowed(string $email): bool
    {
        if ($this->allowedEmailDomains === []) {
            return true;
        }

        $at = strrpos($email, '@');
        if ($at === false) {
            return false;
        }

        $domain = strtolower(substr($email, $at + 1));

        return in_array($domain, $this->allowedEmailDomains, true);
    }
}
