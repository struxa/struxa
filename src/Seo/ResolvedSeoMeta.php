<?php

declare(strict_types=1);

namespace App\Seo;

/**
 * Fully resolved meta for public HTML output (after smart defaults).
 */
final class ResolvedSeoMeta
{
    public function __construct(
        public readonly string $htmlTitle,
        public readonly string $metaDescription,
        public readonly string $canonicalAbsoluteUrl,
        public readonly bool $noindex,
        public readonly string $ogTitle,
        public readonly string $ogDescription,
        public readonly ?string $ogImageAbsoluteUrl,
        public readonly string $twitterTitle,
        public readonly string $twitterDescription,
        public readonly ?string $twitterImageAbsoluteUrl,
        public readonly ?string $schemaJsonLd,
    ) {
    }
}
