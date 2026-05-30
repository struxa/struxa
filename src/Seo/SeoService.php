<?php

declare(strict_types=1);

namespace App\Seo;

use App\Content\ContentEntry;
use App\Content\ContentType;
use App\Media\MediaUrlHelper;
use App\Page\Page;
use App\Settings;
use App\Taxonomy\Taxonomy;
use App\Taxonomy\TaxonomyTerm;

/**
 * Centralised SEO fallbacks: title, description, canonical, OG, Twitter, schema.
 */
final class SeoService
{
    public function __construct(
        private readonly MediaUrlHelper $mediaUrls,
    ) {
    }

    public function resolveForPage(Page $page, string $pathFromRoot, string $siteUrl, ?string $siteName = null, array $breadcrumbs = []): ResolvedSeoMeta
    {
        $siteUrl = rtrim($siteUrl, '/');
        $pathFromRoot = '/' . ltrim($pathFromRoot, '/');
        $suffix = trim((string) Settings::get('seo_title_suffix', ''));
        $baseTitle = $page->seoTitle !== null && trim($page->seoTitle) !== '' ? trim($page->seoTitle) : $page->title;
        $htmlTitle = $baseTitle . ($suffix !== '' ? $suffix : ($siteName !== null && $siteName !== '' ? ' — ' . $siteName : ''));

        $desc = $page->seoDescription !== null && trim($page->seoDescription) !== ''
            ? trim($page->seoDescription)
            : $page->metaDescription();
        $desc = $this->clipDescription($desc, Settings::get('default_meta_description', '') ?? '');

        $canonical = $page->canonicalUrl !== null && trim($page->canonicalUrl) !== ''
            ? MetaTagBuilder::absoluteUrl($siteUrl, trim($page->canonicalUrl))
            : $siteUrl . $pathFromRoot;

        $ogTitle = $page->ogTitle ?? $baseTitle;
        $ogDesc = $page->ogDescription ?? $desc;
        $twTitle = $page->twitterTitle ?? $ogTitle;
        $twDesc = $page->twitterDescription ?? $ogDesc;

        $ogImg = $this->resolveImageUrl($siteUrl, $page->ogImageId, $page->featuredImageId);
        if ($ogImg === '') {
            $ogImg = $this->defaultSiteImage($siteUrl);
        }
        $twImg = $this->resolveImageUrl($siteUrl, $page->twitterImageId, $page->featuredImageId);
        if ($twImg === '') {
            $twImg = $ogImg !== '' ? $ogImg : $this->defaultSiteImage($siteUrl);
        }

        $schema = $page->schemaJson !== null && trim($page->schemaJson) !== '' ? trim($page->schemaJson) : null;
        $schema = $this->withBreadcrumbSchema($schema, $breadcrumbs, $siteUrl);

        return new ResolvedSeoMeta(
            htmlTitle: $htmlTitle,
            metaDescription: $desc,
            canonicalAbsoluteUrl: $canonical,
            noindex: $page->seoNoindex,
            ogTitle: $ogTitle,
            ogDescription: $this->clip($ogDesc, 300),
            ogImageAbsoluteUrl: $ogImg !== '' ? $ogImg : null,
            twitterTitle: $twTitle,
            twitterDescription: $this->clip($twDesc, 300),
            twitterImageAbsoluteUrl: $twImg !== '' ? $twImg : null,
            schemaJsonLd: $schema,
        );
    }

    public function resolveForContentEntry(
        ContentEntry $entry,
        ContentType $type,
        string $pathFromRoot,
        string $siteUrl,
        string $excerptOrBodyPlain,
        ?string $siteName = null,
        array $breadcrumbs = [],
    ): ResolvedSeoMeta {
        $siteUrl = rtrim($siteUrl, '/');
        $pathFromRoot = '/' . ltrim($pathFromRoot, '/');
        $suffix = trim((string) Settings::get('seo_title_suffix', ''));
        $baseTitle = $entry->seoTitle !== null && trim($entry->seoTitle) !== '' ? trim($entry->seoTitle) : $entry->title;
        $htmlTitle = $baseTitle . ($suffix !== '' ? $suffix : ($siteName !== null && $siteName !== '' ? ' — ' . $siteName : ''));

        $desc = $entry->seoDescription !== null && trim($entry->seoDescription) !== ''
            ? trim($entry->seoDescription)
            : $this->clipDescription($excerptOrBodyPlain, Settings::get('default_meta_description', '') ?? '');

        $canonical = $entry->canonicalUrl !== null && trim($entry->canonicalUrl) !== ''
            ? MetaTagBuilder::absoluteUrl($siteUrl, trim($entry->canonicalUrl))
            : $siteUrl . $pathFromRoot;

        $ogTitle = $entry->ogTitle ?? $baseTitle;
        $ogDesc = $entry->ogDescription ?? $desc;
        $twTitle = $entry->twitterTitle ?? $ogTitle;
        $twDesc = $entry->twitterDescription ?? $ogDesc;

        $ogImg = $this->resolveImageUrl($siteUrl, $entry->ogImageId, $entry->featuredImageId);
        if ($ogImg === '') {
            $ogImg = $this->defaultSiteImage($siteUrl);
        }
        $twImg = $this->resolveImageUrl($siteUrl, $entry->twitterImageId, $entry->featuredImageId);
        if ($twImg === '') {
            $twImg = $ogImg !== '' ? $ogImg : $this->defaultSiteImage($siteUrl);
        }

        $schema = $entry->schemaJson !== null && trim($entry->schemaJson) !== '' ? trim($entry->schemaJson) : null;
        $schema = $this->withBreadcrumbSchema($schema, $breadcrumbs, $siteUrl);

        return new ResolvedSeoMeta(
            htmlTitle: $htmlTitle,
            metaDescription: $desc,
            canonicalAbsoluteUrl: $canonical,
            noindex: $entry->seoNoindex,
            ogTitle: $ogTitle,
            ogDescription: $this->clip($ogDesc, 300),
            ogImageAbsoluteUrl: $ogImg !== '' ? $ogImg : null,
            twitterTitle: $twTitle,
            twitterDescription: $this->clip($twDesc, 300),
            twitterImageAbsoluteUrl: $twImg !== '' ? $twImg : null,
            schemaJsonLd: $schema,
        );
    }

    public function resolveForTaxonomyTerm(
        TaxonomyTerm $term,
        Taxonomy $taxonomy,
        ContentType $type,
        string $pathFromRoot,
        string $siteUrl,
        ?string $siteName = null,
    ): ResolvedSeoMeta {
        $siteUrl = rtrim($siteUrl, '/');
        $pathFromRoot = '/' . ltrim($pathFromRoot, '/');
        $suffix = trim((string) Settings::get('seo_title_suffix', ''));
        $baseTitle = $term->seoTitle !== null && trim($term->seoTitle) !== '' ? trim($term->seoTitle) : $term->name;
        $htmlTitle = $baseTitle . ' — ' . $type->name . ($suffix !== '' ? $suffix : ($siteName !== null && $siteName !== '' ? ' — ' . $siteName : ''));

        $rawDesc = $term->seoDescription ?? $term->description ?? $taxonomy->description ?? '';
        $desc = $this->clipDescription((string) $rawDesc, Settings::get('default_meta_description', '') ?? '');

        $canonical = $term->canonicalUrl !== null && trim($term->canonicalUrl) !== ''
            ? MetaTagBuilder::absoluteUrl($siteUrl, trim($term->canonicalUrl))
            : $siteUrl . $pathFromRoot;

        $ogTitle = $term->ogTitle ?? $baseTitle;
        $ogDesc = $term->ogDescription ?? $desc;
        $twTitle = $term->twitterTitle ?? $ogTitle;
        $twDesc = $term->twitterDescription ?? $ogDesc;

        $ogImg = $this->resolveImageUrl($siteUrl, $term->ogImageId, null);
        if ($ogImg === '') {
            $ogImg = $this->defaultSiteImage($siteUrl);
        }
        $twImg = $this->resolveImageUrl($siteUrl, $term->twitterImageId, null);
        if ($twImg === '') {
            $twImg = $ogImg !== '' ? $ogImg : $this->defaultSiteImage($siteUrl);
        }

        $schema = $term->schemaJson !== null && trim($term->schemaJson) !== '' ? trim($term->schemaJson) : null;

        return new ResolvedSeoMeta(
            htmlTitle: $htmlTitle,
            metaDescription: $desc,
            canonicalAbsoluteUrl: $canonical,
            noindex: $term->seoNoindex,
            ogTitle: $ogTitle,
            ogDescription: $this->clip($ogDesc, 300),
            ogImageAbsoluteUrl: $ogImg !== '' ? $ogImg : null,
            twitterTitle: $twTitle,
            twitterDescription: $this->clip($twDesc, 300),
            twitterImageAbsoluteUrl: $twImg !== '' ? $twImg : null,
            schemaJsonLd: $schema,
        );
    }

    /**
     * @param array<string, string> $settings from SiteSettingsService::forTwig()
     */
    public function resolveSiteHome(array $settings, string $siteUrl): ResolvedSeoMeta
    {
        $siteUrl = rtrim($siteUrl, '/');
        $name = $settings['site_name'] ?? 'Site';
        $title = trim($settings['default_meta_title'] ?? '') !== '' ? trim($settings['default_meta_title']) : $name;
        $desc = trim($settings['default_meta_description'] ?? '') !== '';

        return new ResolvedSeoMeta(
            htmlTitle: $title,
            metaDescription: $desc ? trim($settings['default_meta_description']) : '',
            canonicalAbsoluteUrl: $siteUrl . '/',
            noindex: false,
            ogTitle: $title,
            ogDescription: $desc ? trim($settings['default_meta_description']) : '',
            ogImageAbsoluteUrl: $this->defaultSiteImage($siteUrl) ?: null,
            twitterTitle: $title,
            twitterDescription: $desc ? trim($settings['default_meta_description']) : '',
            twitterImageAbsoluteUrl: $this->defaultSiteImage($siteUrl) ?: null,
            schemaJsonLd: null,
        );
    }

    /**
     * Public index listing for a content type (e.g. /blog).
     */
    public function resolveForContentTypeIndex(ContentType $type, string $pathFromRoot, string $siteUrl, ?string $siteName = null): ResolvedSeoMeta
    {
        $siteUrl = rtrim($siteUrl, '/');
        $pathFromRoot = '/' . ltrim($pathFromRoot, '/');
        $suffix = trim((string) Settings::get('seo_title_suffix', ''));
        $baseTitle = $type->name;
        $htmlTitle = $baseTitle . ($suffix !== '' ? $suffix : ($siteName !== null && $siteName !== '' ? ' — ' . $siteName : ''));

        $rawDesc = (string) ($type->description ?? '');
        $desc = $this->clipDescription($rawDesc, Settings::get('default_meta_description', '') ?? '');

        $canonical = $siteUrl . $pathFromRoot;

        $ogImg = $this->defaultSiteImage($siteUrl);

        return new ResolvedSeoMeta(
            htmlTitle: $htmlTitle,
            metaDescription: $desc,
            canonicalAbsoluteUrl: $canonical,
            noindex: false,
            ogTitle: $baseTitle,
            ogDescription: $this->clip($desc, 300),
            ogImageAbsoluteUrl: $ogImg !== '' ? $ogImg : null,
            twitterTitle: $baseTitle,
            twitterDescription: $this->clip($desc, 300),
            twitterImageAbsoluteUrl: $ogImg !== '' ? $ogImg : null,
            schemaJsonLd: null,
        );
    }

    /**
     * @param list<array{name: string, url?: string}> $breadcrumbs
     */
    private function withBreadcrumbSchema(?string $schema, array $breadcrumbs, string $siteUrl): ?string
    {
        if ($breadcrumbs === []) {
            return $schema;
        }
        $bc = BreadcrumbSchemaBuilder::build($breadcrumbs, $siteUrl);

        return SchemaJsonLdMerger::merge($schema, $bc);
    }

    private function resolveImageUrl(string $siteUrl, ?int $primaryId, ?int $fallbackFeaturedId): string
    {
        if ($primaryId !== null && $primaryId > 0) {
            $p = $this->mediaUrls->pathForId($primaryId);
            if ($p !== '') {
                return MetaTagBuilder::absoluteUrl($siteUrl, $p);
            }
        }
        if ($fallbackFeaturedId !== null && $fallbackFeaturedId > 0) {
            $p = $this->mediaUrls->pathForId($fallbackFeaturedId);
            if ($p !== '') {
                return MetaTagBuilder::absoluteUrl($siteUrl, $p);
            }
        }

        return '';
    }

    private function defaultSiteImage(string $siteUrl): string
    {
        $og = Settings::get('seo_default_og_image_media_id', '');
        $tw = Settings::get('seo_default_twitter_image_media_id', '');
        $id = ctype_digit((string) $og) && (int) $og > 0 ? (int) $og : (ctype_digit((string) $tw) && (int) $tw > 0 ? (int) $tw : 0);
        if ($id <= 0) {
            return '';
        }
        $p = $this->mediaUrls->pathForId($id);

        return $p !== '' ? MetaTagBuilder::absoluteUrl($siteUrl, $p) : '';
    }

    private function clipDescription(string $primary, string $siteFallback): string
    {
        $t = trim(preg_replace('/\s+/', ' ', strip_tags($primary)) ?? '');
        if ($t === '') {
            $t = trim($siteFallback);
        }
        if (mb_strlen($t) > 160) {
            return mb_substr($t, 0, 157) . '…';
        }

        return $t;
    }

    private function clip(string $s, int $max): string
    {
        $s = trim($s);
        if (mb_strlen($s) <= $max) {
            return $s;
        }

        return mb_substr($s, 0, $max - 1) . '…';
    }
}
