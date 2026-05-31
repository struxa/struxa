<?php

declare(strict_types=1);

namespace App\Seo;

use App\Admin\AfterSaveRedirect;
use App\Content\ContentEntryRepository;
use App\Content\ContentType;
use App\Settings;
use App\Taxonomy\Taxonomy;
use App\Taxonomy\TaxonomyTermRepository;

/**
 * WordPress-style automatic 301 redirects when public slugs change.
 */
final class SlugChangeRedirectService
{
    public const SETTING_KEY = 'seo_auto_redirect_slug_change';

    public function __construct(
        private readonly RedirectRepository $redirects,
    ) {
    }

    public static function isEnabled(): bool
    {
        return Settings::get(self::SETTING_KEY, '1') === '1';
    }

    public function forPage(
        int $pageId,
        string $oldSlug,
        string $newSlug,
        string $status,
        ?string $publishedAt,
        string $siteUrl,
    ): SlugRedirectResult {
        if ($oldSlug === $newSlug || $status !== 'published') {
            return SlugRedirectResult::none();
        }

        $dest = AfterSaveRedirect::pagePublicUrl($siteUrl, $newSlug, $status, $pageId, $publishedAt);
        if ($dest === null) {
            return SlugRedirectResult::none();
        }

        return $this->recordPathChange(
            $this->pagePath($oldSlug),
            $dest,
        );
    }

    public function forEntry(
        ContentType $type,
        string $oldSlug,
        string $newSlug,
        string $status,
        ?string $publishedAt,
        string $siteUrl,
    ): SlugRedirectResult {
        if ($oldSlug === $newSlug || $status !== 'published' || !$type->hasPublicRoute) {
            return SlugRedirectResult::none();
        }

        $dest = AfterSaveRedirect::entryPublicUrl($siteUrl, $type, $newSlug, $status, $publishedAt);
        if ($dest === null) {
            return SlugRedirectResult::none();
        }

        return $this->recordPathChange(
            $this->entryPath($type->slug, $oldSlug),
            $dest,
        );
    }

    public function forContentTypeSlug(
        ContentType $type,
        string $oldTypeSlug,
        string $newTypeSlug,
        ContentEntryRepository $entries,
        string $siteUrl,
    ): int {
        if ($oldTypeSlug === $newTypeSlug || !$type->hasPublicRoute) {
            return 0;
        }

        $created = 0;
        $base = rtrim($siteUrl, '/');
        if ($this->recordPathChange('/' . rawurlencode($oldTypeSlug), $base . '/' . rawurlencode($newTypeSlug))->created) {
            ++$created;
        }

        foreach ($entries->publishedSlugsForType($type->id) as $entrySlug) {
            if ($this->recordPathChange(
                $this->entryPath($oldTypeSlug, $entrySlug),
                $base . '/' . rawurlencode($newTypeSlug) . '/' . rawurlencode($entrySlug),
            )->created) {
                ++$created;
            }
        }

        return $created;
    }

    public function forTaxonomySlug(
        ContentType $type,
        Taxonomy $taxonomy,
        string $oldTaxonomySlug,
        string $newTaxonomySlug,
        TaxonomyTermRepository $terms,
        string $siteUrl,
    ): int {
        if ($oldTaxonomySlug === $newTaxonomySlug || !$type->hasPublicRoute) {
            return 0;
        }

        $created = 0;
        $base = rtrim($siteUrl, '/');
        foreach ($terms->forTaxonomyOrdered($taxonomy->id) as $term) {
            if ($this->recordPathChange(
                $this->taxonomyTermPath($type->slug, $oldTaxonomySlug, $term->slug),
                $base . '/' . rawurlencode($type->slug) . '/' . rawurlencode($newTaxonomySlug) . '/' . rawurlencode($term->slug),
            )->created) {
                ++$created;
            }
        }

        return $created;
    }

    public function forTaxonomyTerm(
        ContentType $type,
        Taxonomy $taxonomy,
        string $oldTermSlug,
        string $newTermSlug,
        string $siteUrl,
    ): SlugRedirectResult {
        if ($oldTermSlug === $newTermSlug || !$type->hasPublicRoute) {
            return SlugRedirectResult::none();
        }

        $base = rtrim($siteUrl, '/');
        $dest = $base . '/' . rawurlencode($type->slug) . '/'
            . rawurlencode($taxonomy->slug) . '/' . rawurlencode($newTermSlug);

        return $this->recordPathChange(
            $this->taxonomyTermPath($type->slug, $taxonomy->slug, $oldTermSlug),
            $dest,
        );
    }

    public function recordPathChange(string $fromPath, string $toUrl, int $statusCode = 301): SlugRedirectResult
    {
        if (!self::isEnabled()) {
            return SlugRedirectResult::skipped();
        }

        $fromPath = RedirectRepository::normalizePath($fromPath);
        $toUrl = trim($toUrl);
        if ($toUrl === '') {
            return SlugRedirectResult::none();
        }

        $toPath = self::destinationPath($toUrl);
        if ($toPath !== null && RedirectRepository::normalizePath($toPath) === $fromPath) {
            return SlugRedirectResult::none();
        }

        $chainUpdated = $this->redirects->retargetDestinations($fromPath, $toUrl);
        $this->redirects->upsertPath($fromPath, $toUrl, $statusCode);

        return new SlugRedirectResult(true, $fromPath, $toUrl, $chainUpdated);
    }

    public static function appendFlash(string $message, SlugRedirectResult $result): string
    {
        return $message . $result->flashSuffix();
    }

    public static function appendBulkFlash(string $message, int $count): string
    {
        if ($count <= 0) {
            return $message;
        }

        return $message . ' Added ' . $count . ' automatic 301 redirect(s) for slug changes.';
    }

    private function pagePath(string $slug): string
    {
        return '/p/' . rawurlencode($slug);
    }

    private function entryPath(string $typeSlug, string $entrySlug): string
    {
        return '/' . rawurlencode($typeSlug) . '/' . rawurlencode($entrySlug);
    }

    private function taxonomyTermPath(string $typeSlug, string $taxonomySlug, string $termSlug): string
    {
        return '/' . rawurlencode($typeSlug) . '/'
            . rawurlencode($taxonomySlug) . '/' . rawurlencode($termSlug);
    }

    private static function destinationPath(string $toUrl): ?string
    {
        if (str_starts_with($toUrl, '/')) {
            return $toUrl;
        }
        $path = parse_url($toUrl, PHP_URL_PATH);

        return is_string($path) && $path !== '' ? $path : null;
    }
}
