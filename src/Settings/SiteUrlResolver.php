<?php

declare(strict_types=1);

namespace App\Settings;

use App\Settings as CmsSettings;

/**
 * Public site base URL (no trailing slash). Admin → Site settings overrides PHPAUTH_SITE_URL when set.
 */
final class SiteUrlResolver
{
    public static function resolve(): string
    {
        $fromDb = trim(CmsSettings::get('site_url', '') ?? '');
        if ($fromDb !== '') {
            return rtrim($fromDb, '/');
        }

        $fromEnv = $_ENV['PHPAUTH_SITE_URL'] ?? getenv('PHPAUTH_SITE_URL');
        if (is_string($fromEnv) && trim($fromEnv) !== '') {
            return rtrim(trim($fromEnv), '/');
        }

        return 'http://localhost:8080';
    }
}
