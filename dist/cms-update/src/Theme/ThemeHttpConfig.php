<?php

declare(strict_types=1);

namespace App\Theme;

/**
 * Single source for public theme asset URLs and route patterns (avoid scattered literals).
 */
final class ThemeHttpConfig
{
    /** First URL path segment (reserved for content routes). */
    public const ASSET_URL_SEGMENT = 'theme-assets';

    /** Project subdirectory holding installable themes. */
    public const THEMES_DIRECTORY = 'themes';

    public const FALLBACK_THEME_SLUG = 'struxa-theme';

    public const MAX_PARENT_DEPTH = 8;

    public static function assetUrlPrefix(): string
    {
        return '/' . self::ASSET_URL_SEGMENT;
    }

    /**
     * Slim route pattern for the theme asset handler.
     */
    public static function assetRoutePattern(): string
    {
        return self::assetUrlPrefix() . '/{path:.+}';
    }

    /**
     * Absolute URL for a file under themes/{active}/assets/ (path must already be sanitized).
     *
     * @param list<string> $encodedPathSegments path pieces, each rawurlencode'd
     */
    public static function assetUrl(string $siteUrl, array $encodedPathSegments): string
    {
        $siteUrl = rtrim($siteUrl, '/');
        if ($encodedPathSegments === []) {
            return $siteUrl . self::assetUrlPrefix();
        }

        return $siteUrl . self::assetUrlPrefix() . '/' . implode('/', $encodedPathSegments);
    }

    /**
     * Root-relative URL for theme assets (same host/port as the page). Avoids broken CSS when
     * PHPAUTH_SITE_URL does not match how the site is actually accessed (e.g. Docker port mapping).
     *
     * @param list<string> $encodedPathSegments each segment rawurlencode'd
     */
    public static function assetPath(array $encodedPathSegments): string
    {
        if ($encodedPathSegments === []) {
            return self::assetUrlPrefix();
        }

        return self::assetUrlPrefix() . '/' . implode('/', $encodedPathSegments);
    }
}
