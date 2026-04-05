<?php

declare(strict_types=1);

namespace App\Seo;

/**
 * Maps {@see ResolvedSeoMeta} to Twig variables and JSON-LD wrapper.
 */
final class MetaTagBuilder
{
    /**
     * @return array<string, mixed>
     */
    public static function twigVars(ResolvedSeoMeta $m): array
    {
        return [
            'struxa_seo' => true,
            'struxa_html_title' => $m->htmlTitle,
            'struxa_meta_description' => $m->metaDescription,
            'struxa_canonical_url' => $m->canonicalAbsoluteUrl,
            'struxa_robots_noindex' => $m->noindex,
            'struxa_og_title' => $m->ogTitle,
            'struxa_og_description' => $m->ogDescription,
            'struxa_og_image' => $m->ogImageAbsoluteUrl,
            'struxa_twitter_title' => $m->twitterTitle,
            'struxa_twitter_description' => $m->twitterDescription,
            'struxa_twitter_image' => $m->twitterImageAbsoluteUrl,
            'struxa_schema_json_ld' => self::jsonLdSafeForScript($m->schemaJsonLd),
        ];
    }

    /**
     * Re-serializes JSON-LD so it is safe inside {@code <script type="application/ld+json">} (no {@code </script>} breakout).
     * Invalid JSON in storage returns null so we do not echo attacker-controlled markup.
     */
    public static function jsonLdSafeForScript(?string $json): ?string
    {
        if ($json === null) {
            return null;
        }
        $trim = trim($json);
        if ($trim === '') {
            return null;
        }
        $data = json_decode($trim, true, 512);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        $encoded = json_encode(
            $data,
            JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );

        return $encoded !== false ? $encoded : null;
    }

    public static function absoluteUrl(string $siteUrl, string $pathOrUrl): string
    {
        $pathOrUrl = trim($pathOrUrl);
        if ($pathOrUrl === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $pathOrUrl) === 1) {
            return $pathOrUrl;
        }
        $base = rtrim($siteUrl, '/');
        $path = $pathOrUrl[0] === '/' ? $pathOrUrl : '/' . $pathOrUrl;

        return $base . $path;
    }
}
