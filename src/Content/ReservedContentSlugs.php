<?php

declare(strict_types=1);

namespace App\Content;

use App\Theme\ThemeHttpConfig;

/**
 * URL segments reserved for core and plugin routes (content type slugs must not collide).
 *
 * Core lists only platform paths every Struxa install uses. Plugins register their own
 * segments via {@see registerPluginReservedSlugs()} from {@see \App\Plugin\PluginBootContext}.
 */
final class ReservedContentSlugs
{
    /** @var list<string> Platform routes — never site- or product-specific slugs. */
    private const CORE_RESERVED = [
        'admin',
        'api',
        'auth',
        'p',
        'preview',
        'schedule',
        'login',
        'register',
        'logout',
        'search',
        'comments',
        'uploads',
        'css',
        'js',
        'media',
        'media-rs',
        'favicon.ico',
        'robots.txt',
        'sitemap.xml',
        'site.webmanifest',
        ThemeHttpConfig::ASSET_URL_SEGMENT,
    ];

    /** @var array<string, true> First-path segments registered by active plugins at boot. */
    private static array $pluginReserved = [];

    public static function isReserved(string $slug): bool
    {
        $s = self::normalize($slug);
        if ($s === '') {
            return false;
        }

        return in_array($s, self::CORE_RESERVED, true) || isset(self::$pluginReserved[$s]);
    }

    /**
     * Register URL segments owned by a plugin (call from PluginServiceProvider::boot()).
     *
     * @param list<string> $slugs First path segment for each public route, e.g. ['my-catalog'] for GET /my-catalog
     */
    public static function registerPluginReservedSlugs(array $slugs): void
    {
        foreach ($slugs as $slug) {
            $s = self::normalize($slug);
            if ($s === '' || !self::isValidSegment($s)) {
                continue;
            }
            self::$pluginReserved[$s] = true;
        }
    }

    /**
     * Alias for {@see registerPluginReservedSlugs()} (same behavior).
     *
     * @param list<string> $slugs
     */
    public static function registerReservedContentSlugs(array $slugs): void
    {
        self::registerPluginReservedSlugs($slugs);
    }

    /**
     * @return list<string> Core platform segments (plugins are not included).
     */
    public static function coreReservedSlugs(): array
    {
        return self::CORE_RESERVED;
    }

    /**
     * @return list<string> Plugin-registered segments for the current request lifecycle.
     */
    public static function pluginReservedSlugs(): array
    {
        return array_keys(self::$pluginReserved);
    }

    /** @internal Tests only — resets plugin registrations between cases. */
    public static function resetPluginReservedSlugsForTesting(): void
    {
        self::$pluginReserved = [];
    }

    private static function normalize(string $slug): string
    {
        return strtolower(trim($slug));
    }

    private static function isValidSegment(string $slug): bool
    {
        return preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) === 1;
    }
}
