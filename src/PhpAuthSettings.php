<?php

declare(strict_types=1);

namespace App;

use PHPAuth\Config;

final class PhpAuthSettings
{
    public const DEV_SITE_KEY = 'dev-only-change-PHPAUTH_SITE_KEY-in-env-min-twenty-chars';

    public const MIN_SITE_KEY_LENGTH = 32;

    /**
     * PHPAuth array config (see vendor/phpauth/phpauth/database_defs/database_mysql.sql).
     * Env overrides: PHPAUTH_SITE_KEY, PHPAUTH_SITE_URL, PHPAUTH_COOKIE_SECURE, PHPAUTH_COOKIE_SAMESITE,
     * PHPAUTH_SITE_EMAIL, SITE_NAME.
     */
    public static function fromEnv(): array
    {
        $siteKey = $_ENV['PHPAUTH_SITE_KEY'] ?? '';
        if ($siteKey === '') {
            $siteKey = self::DEV_SITE_KEY;
        }

        $base = self::defaults();
        $base['site_key'] = $siteKey;
        $base['site_url'] = rtrim($_ENV['PHPAUTH_SITE_URL'] ?? 'http://localhost:8080', '/');
        $base['site_name'] = $_ENV['SITE_NAME'] ?? 'Your Studio';
        $base['site_email'] = $_ENV['PHPAUTH_SITE_EMAIL'] ?? 'no-reply@localhost';
        $base['cookie_secure'] = ($_ENV['PHPAUTH_COOKIE_SECURE'] ?? '0') === '1';
        $base['cookie_samesite'] = $_ENV['PHPAUTH_COOKIE_SAMESITE'] ?? 'Lax';

        $remember = isset($_ENV['PHPAUTH_COOKIE_REMEMBER']) ? trim((string) $_ENV['PHPAUTH_COOKIE_REMEMBER']) : '';
        if ($remember !== '' && strtotime($remember) !== false) {
            $base['cookie_remember'] = $remember;
        }

        return $base;
    }

    private static function defaults(): array
    {
        return [
            'attack_mitigation_time' => '+30 minutes',
            'attempts_before_ban' => '30',
            'attempts_before_verify' => '5',
            'bcrypt_cost' => '10',
            'cookie_domain' => '',
            'cookie_forget' => '+30 minutes',
            'cookie_http' => true,
            'cookie_name' => 'phpauth_session_cookie',
            'cookie_path' => '/',
            'cookie_remember' => '+1 year',
            'cookie_renew' => '+5 minutes',
            'allow_concurrent_sessions' => false,
            'emailmessage_suppress_activation' => '0',
            'emailmessage_suppress_reset' => '0',
            'site_activation_page' => 'activate',
            'site_activation_page_append_code' => '0',
            'site_password_reset_page' => 'reset',
            'site_password_reset_page_append_code' => '0',
            'site_timezone' => 'UTC',
            'site_language' => 'en_GB',
            'smtp' => '0',
            'smtp_debug' => '0',
            'smtp_auth' => '1',
            'smtp_host' => 'smtp.example.com',
            'smtp_password' => 'password',
            'smtp_port' => '25',
            'smtp_security' => '',
            'smtp_username' => 'email@example.com',
            'table_attempts' => 'phpauth_attempts',
            'table_requests' => 'phpauth_requests',
            'table_sessions' => 'phpauth_sessions',
            'table_users' => 'phpauth_users',
            'table_emails_banned' => 'phpauth_emails_banned',
            'table_translations' => '',
            'verify_email_max_length' => '100',
            'verify_email_min_length' => '5',
            // Storefront registrations: refuse extremely short passwords. PHPAuth's check is a
            // hard floor; the admin installer still enforces its own 10-char minimum on top.
            'verify_password_min_length' => '8',
            'request_key_expiration' => '+10 minutes',
            'translation_source' => 'php',
            'custom_datetime_format' => 'Y-m-d H:i',
            'uses_session' => 0,
            'mail_charset' => 'UTF-8',
        ];
    }

    public static function configType(): string
    {
        return Config::CONFIG_TYPE_ARRAY;
    }

    /**
     * Refuse to boot the web app with a missing or weak site key after install (session cookie binding).
     */
    public static function assertInstalledSiteKeyOrExit(string $projectRoot): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }

        $lock = $projectRoot . '/storage/installed.lock';
        if (!is_file($lock)) {
            return;
        }

        $siteKey = trim((string) ($_ENV['PHPAUTH_SITE_KEY'] ?? ''));
        if ($siteKey === '' || $siteKey === self::DEV_SITE_KEY || strlen($siteKey) < self::MIN_SITE_KEY_LENGTH) {
            http_response_code(503);
            header('Content-Type: text/html; charset=utf-8');
            echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Configuration required</title>'
                . '<style>body{font-family:system-ui;max-width:40rem;margin:3rem auto;padding:0 1.25rem;line-height:1.55}</style></head><body>'
                . '<h1>Configuration required</h1>'
                . '<p>Set <code>PHPAUTH_SITE_KEY</code> in <code>.env</code> to a random string of at least '
                . (string) self::MIN_SITE_KEY_LENGTH
                . ' characters before running this site in production.</p>'
                . '</body></html>';
            exit(1);
        }
    }
}
