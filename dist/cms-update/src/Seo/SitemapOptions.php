<?php

declare(strict_types=1);

namespace App\Seo;

use App\Settings;

/**
 * Feature flags for {@see SitemapService} (stored in cms_settings, autoloaded).
 */
final class SitemapOptions
{
    public function __construct(
        public readonly bool $includePages = true,
        public readonly bool $includeEntries = true,
        public readonly bool $includeTaxonomyArchives = true,
    ) {
    }

    public static function fromSettings(): self
    {
        return new self(
            self::truthy(Settings::get('sitemap_include_pages', '1')),
            self::truthy(Settings::get('sitemap_include_entries', '1')),
            self::truthy(Settings::get('sitemap_include_taxonomy_archives', '1')),
        );
    }

    public static function sitemapPubliclyEnabled(): bool
    {
        return self::truthy(Settings::get('sitemap_enabled', '1'));
    }

    private static function truthy(?string $v): bool
    {
        if ($v === null || $v === '') {
            return false;
        }

        return $v === '1' || strtolower($v) === 'true' || strtolower($v) === 'yes';
    }
}
