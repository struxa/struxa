<?php

declare(strict_types=1);

namespace App\Seo;

use App\Filter\FilterHook;
use App\Filter\Filters;

/**
 * Maps {@see ResolvedSeoMeta} to/from filter arrays for {@see FilterHook::SEO_META}.
 */
final class SeoMetaFilter
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(ResolvedSeoMeta $meta): array
    {
        return [
            'html_title' => $meta->htmlTitle,
            'meta_description' => $meta->metaDescription,
            'canonical_absolute_url' => $meta->canonicalAbsoluteUrl,
            'noindex' => $meta->noindex,
            'og_title' => $meta->ogTitle,
            'og_description' => $meta->ogDescription,
            'og_image_absolute_url' => $meta->ogImageAbsoluteUrl,
            'twitter_title' => $meta->twitterTitle,
            'twitter_description' => $meta->twitterDescription,
            'twitter_image_absolute_url' => $meta->twitterImageAbsoluteUrl,
            'schema_json_ld' => $meta->schemaJsonLd,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data, ResolvedSeoMeta $fallback): ResolvedSeoMeta
    {
        return new ResolvedSeoMeta(
            htmlTitle: self::string($data['html_title'] ?? null, $fallback->htmlTitle),
            metaDescription: self::string($data['meta_description'] ?? null, $fallback->metaDescription),
            canonicalAbsoluteUrl: self::string($data['canonical_absolute_url'] ?? null, $fallback->canonicalAbsoluteUrl),
            noindex: self::bool($data['noindex'] ?? null, $fallback->noindex),
            ogTitle: self::string($data['og_title'] ?? null, $fallback->ogTitle),
            ogDescription: self::string($data['og_description'] ?? null, $fallback->ogDescription),
            ogImageAbsoluteUrl: self::nullableString($data['og_image_absolute_url'] ?? null, $fallback->ogImageAbsoluteUrl),
            twitterTitle: self::string($data['twitter_title'] ?? null, $fallback->twitterTitle),
            twitterDescription: self::string($data['twitter_description'] ?? null, $fallback->twitterDescription),
            twitterImageAbsoluteUrl: self::nullableString($data['twitter_image_absolute_url'] ?? null, $fallback->twitterImageAbsoluteUrl),
            schemaJsonLd: self::nullableString($data['schema_json_ld'] ?? null, $fallback->schemaJsonLd),
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function apply(ResolvedSeoMeta $meta, array $context): ResolvedSeoMeta
    {
        $filtered = Filters::apply(FilterHook::SEO_META, self::toArray($meta), $context);

        return is_array($filtered) ? self::fromArray($filtered, $meta) : $meta;
    }

    private static function string(mixed $value, string $fallback): string
    {
        return is_string($value) ? $value : $fallback;
    }

    private static function nullableString(mixed $value, ?string $fallback): ?string
    {
        if ($value === null) {
            return null;
        }

        return is_string($value) ? ($value !== '' ? $value : null) : $fallback;
    }

    private static function bool(mixed $value, bool $fallback): bool
    {
        return is_bool($value) ? $value : $fallback;
    }
}
