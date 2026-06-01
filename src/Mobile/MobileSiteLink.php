<?php

declare(strict_types=1);

namespace App\Mobile;

/**
 * Deep links and install URLs for the Struxa client app.
 */
final class MobileSiteLink
{
    public const APP_SCHEME = 'struxa';
    public const ADD_SITE_PATH = 'add-site';

    public static function deepLinkAddSite(string $siteUrl): string
    {
        $siteUrl = rtrim(trim($siteUrl), '/');

        return self::APP_SCHEME . '://' . self::ADD_SITE_PATH . '?url=' . rawurlencode($siteUrl);
    }

    /**
     * HTTPS fallback page on the site itself (works when the app is not installed).
     */
    public static function webAddSitePath(string $siteUrl): string
    {
        return rtrim(trim($siteUrl), '/') . '/mobile/add';
    }
}
