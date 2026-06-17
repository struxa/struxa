<?php

declare(strict_types=1);

namespace App\Auth;

use App\Settings;

/**
 * Firebase Authentication (client + server). Configured in Admin → Site settings.
 */
final class FirebaseConfig
{
    /**
     * @param list<string> $allowedEmailDomains Lowercase hostnames (no @). Empty = any domain.
     */
    public function __construct(
        public readonly string $apiKey,
        public readonly string $authDomain,
        public readonly string $projectId,
        public readonly string $appId,
        public readonly string $storageBucket,
        public readonly string $messagingSenderId,
        public readonly string $serviceAccountJson,
        public readonly array $allowedEmailDomains,
        public readonly bool $autoProvision,
    ) {
    }

    public static function fromSettings(): ?self
    {
        if (trim((string) (Settings::get('firebase_enabled', '0') ?? '0')) !== '1') {
            return null;
        }

        $apiKey = trim((string) (Settings::get('firebase_api_key', '') ?? ''));
        $authDomain = trim((string) (Settings::get('firebase_auth_domain', '') ?? ''));
        $projectId = trim((string) (Settings::get('firebase_project_id', '') ?? ''));
        $appId = trim((string) (Settings::get('firebase_app_id', '') ?? ''));
        if ($apiKey === '' || $authDomain === '' || $projectId === '' || $appId === '') {
            return null;
        }

        $storageBucket = trim((string) (Settings::get('firebase_storage_bucket', '') ?? ''));
        $messagingSenderId = trim((string) (Settings::get('firebase_messaging_sender_id', '') ?? ''));
        $serviceAccountJson = trim((string) (Settings::get('firebase_service_account_json', '') ?? ''));

        $domainsRaw = trim((string) (Settings::get('firebase_allowed_domains', '') ?? ''));
        $domains = [];
        if ($domainsRaw !== '') {
            foreach (explode(',', $domainsRaw) as $part) {
                $d = strtolower(trim(str_replace('@', '', $part)));
                if ($d !== '') {
                    $domains[] = $d;
                }
            }
        }

        $autoProvision = trim((string) (Settings::get('firebase_auto_provision', '0') ?? '0')) === '1';

        return new self(
            $apiKey,
            $authDomain,
            $projectId,
            $appId,
            $storageBucket,
            $messagingSenderId,
            $serviceAccountJson,
            $domains,
            $autoProvision,
        );
    }

    /**
     * Public web client config (no secrets).
     *
     * @return array<string, string>
     */
    public function clientConfig(): array
    {
        $out = [
            'apiKey' => $this->apiKey,
            'authDomain' => $this->authDomain,
            'projectId' => $this->projectId,
            'appId' => $this->appId,
        ];
        if ($this->storageBucket !== '') {
            $out['storageBucket'] = $this->storageBucket;
        }
        if ($this->messagingSenderId !== '') {
            $out['messagingSenderId'] = $this->messagingSenderId;
        }

        return $out;
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

    public function hasServiceAccount(): bool
    {
        return $this->serviceAccountJson !== '';
    }
}
