<?php

declare(strict_types=1);

namespace App\Content;

use App\Theme\ThemeHttpConfig;

/**
 * URL segments reserved for core routes (content type slugs must not collide).
 */
final class ReservedContentSlugs
{
    /** @var list<string> */
    private const RESERVED = [
        'admin',
        'api',
        'p',
        'login',
        'register',
        'logout',
        'uploads',
        'css',
        'js',
        'media',
        'favicon.ico',
        'robots.txt',
        'sitemap.xml',
        'site.webmanifest',
        ThemeHttpConfig::ASSET_URL_SEGMENT,
    ];

    public static function isReserved(string $slug): bool
    {
        $s = strtolower(trim($slug));

        return in_array($s, self::RESERVED, true);
    }
}
