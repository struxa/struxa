<?php

declare(strict_types=1);

namespace App\Richtext;

/**
 * Builds sanitized-friendly embed markup for supported providers.
 */
final class OEmbedRenderer
{
    public static function render(OEmbedMatch $match): string
    {
        return match ($match->provider) {
            OEmbedMatch::PROVIDER_YOUTUBE => self::youtube($match->id),
            OEmbedMatch::PROVIDER_TWITTER => self::twitter($match->id),
            default => '',
        };
    }

    public static function renderUrl(string $url): ?string
    {
        $match = OEmbedUrlParser::parse($url);
        if ($match === null) {
            return null;
        }
        $html = self::render($match);

        return $html !== '' ? $html : null;
    }

    private static function youtube(string $id): string
    {
        $src = 'https://www.youtube-nocookie.com/embed/' . rawurlencode($id);

        return '<div class="cms-oembed cms-oembed--youtube">'
            . '<iframe src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '"'
            . ' title="YouTube video" width="560" height="315" frameborder="0"></iframe></div>';
    }

    private static function twitter(string $id): string
    {
        $src = 'https://platform.twitter.com/embed/Tweet.html?id=' . rawurlencode($id) . '&amp;dnt=true';

        return '<div class="cms-oembed cms-oembed--twitter">'
            . '<iframe src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '"'
            . ' title="Post on X" width="550" height="420" frameborder="0" scrolling="no"></iframe></div>';
    }
}
