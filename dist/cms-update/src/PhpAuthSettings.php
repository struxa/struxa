<?php

declare(strict_types=1);

namespace App;

use PHPAuth\Config;

final class PhpAuthSettings
{
    /**
     * PHPAuth array config (see vendor/phpauth/phpauth/database_defs/database_mysql.sql).
     * Env overrides: PHPAUTH_SITE_KEY, PHPAUTH_SITE_URL, PHPAUTH_COOKIE_SECURE, PHPAUTH_COOKIE_SAMESITE,
     * PHPAUTH_SITE_EMAIL, SITE_NAME.
     */
    public static function fromEnv(): array
    {
        $siteKey = $_ENV['PHPAUTH_SITE_KEY'] ?? '';
        if ($siteKey === '') {
            $siteKey = 'dev-only-change-PHPAUTH_SITE_KEY-in-env-min-twenty-chars';
        }

        $base = self::defaults();
        $base['site_key'] = $siteKey;
        $base['site_url'] = rtrim($_ENV['PHPAUTH_SITE_URL'] ?? 'http://localhost:8080', '/');
        $base['site_name'] = $_ENV['SITE_NAME'] ?? 'Your Studio';
        $base['site_email'] = $_ENV['PHPAUTH_SITE_EMAIL'] ?? 'no-reply@localhost';
        $base['cookie_secure'] = ($_ENV['PHPAUTH_COOKIE_SECURE'] ?? '0') === '1';
        $base['cookie_samesite'] = $_ENV['PHPAUTH_COOKIE_SAMESITE'] ?? 'Lax';

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
            'cookie_remember' => '+1 month',
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
            'verify_password_min_length' => '3',
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
}
