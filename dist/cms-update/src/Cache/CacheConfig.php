<?php

declare(strict_types=1);

namespace App\Cache;

use App\Settings;

/**
 * Env + cms_settings resolution for cache behaviour.
 */
final class CacheConfig
{
    public static function publicCacheEnabled(): bool
    {
        $db = Settings::get('cache_public_enabled');
        if ($db === '1') {
            return true;
        }
        if ($db === '0') {
            return false;
        }

        return ($_ENV['STRUXA_PUBLIC_CACHE'] ?? '0') === '1';
    }

    public static function publicTtlSeconds(): int
    {
        $db = Settings::get('cache_public_ttl_sec');
        if ($db !== null && $db !== '' && ctype_digit($db)) {
            return max(30, min(86400, (int) $db));
        }
        $env = (int) ($_ENV['STRUXA_PUBLIC_CACHE_TTL'] ?? 300);

        return max(30, min(86400, $env > 0 ? $env : 300));
    }

    public static function internalTtlSeconds(): int
    {
        $env = (int) ($_ENV['STRUXA_INTERNAL_CACHE_TTL'] ?? 120);

        return max(15, min(3600, $env > 0 ? $env : 120));
    }

    public static function preferMinifiedAssets(): bool
    {
        return ($_ENV['STRUXA_ASSETS_PREFER_MIN'] ?? '0') === '1'
            || Settings::get('assets_prefer_minified') === '1';
    }

    public static function sendDebugCacheHeaders(): bool
    {
        return ($_ENV['STRUXA_CACHE_DEBUG_HEADERS'] ?? '0') === '1';
    }

    /**
     * Skip storing public responses larger than this (bytes) to avoid huge cache files.
     */
    public static function publicCacheMaxBodyBytes(): int
    {
        $env = (int) ($_ENV['STRUXA_PUBLIC_CACHE_MAX_BODY_BYTES'] ?? 5_000_000);

        return max(256_000, min(20_000_000, $env > 0 ? $env : 5_000_000));
    }

    /**
     * Human-readable store label for admin (file-backed; Redis optional later).
     */
    public static function activePublicStoreDriverLabel(): string
    {
        if (extension_loaded('redis')
            && class_exists(\Redis::class)
            && trim((string) ($_ENV['STRUXA_REDIS_URL'] ?? '')) !== '') {
            return 'File (namespaces on disk) · Redis extension available (not wired to page cache)';
        }

        return 'File — namespaces under storage/cache/';
    }
}
