<?php

declare(strict_types=1);

namespace App\Richtext;

/**
 * Parsed oEmbed target (YouTube video or X/Twitter post).
 */
final class OEmbedMatch
{
    public const PROVIDER_YOUTUBE = 'youtube';
    public const PROVIDER_TWITTER = 'twitter';

    public function __construct(
        public readonly string $provider,
        public readonly string $id,
        public readonly string $sourceUrl,
    ) {
    }
}
