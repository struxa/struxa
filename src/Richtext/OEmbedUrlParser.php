<?php

declare(strict_types=1);

namespace App\Richtext;

/**
 * Recognizes YouTube and X (Twitter) URLs without calling remote oEmbed APIs.
 */
final class OEmbedUrlParser
{
    public static function parse(string $url): ?OEmbedMatch
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        if (!preg_match('#^https?://#i', $url)) {
            return null;
        }

        $youtube = self::parseYouTube($url);
        if ($youtube !== null) {
            return $youtube;
        }

        return self::parseTwitter($url);
    }

    private static function parseYouTube(string $url): ?OEmbedMatch
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'])) {
            return null;
        }
        $host = strtolower((string) $parts['host']);
        $path = (string) ($parts['path'] ?? '');

        if (in_array($host, ['youtu.be', 'www.youtu.be'], true)) {
            $id = trim($path, '/');
            if (self::isYouTubeId($id)) {
                return new OEmbedMatch(OEmbedMatch::PROVIDER_YOUTUBE, $id, $url);
            }

            return null;
        }

        if (!in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com'], true)) {
            return null;
        }

        if (preg_match('#^/(?:embed|shorts|live)/([\w-]{11})#', $path, $m) === 1) {
            return new OEmbedMatch(OEmbedMatch::PROVIDER_YOUTUBE, $m[1], $url);
        }

        if ($path === '/watch' || str_starts_with($path, '/watch/')) {
            parse_str((string) ($parts['query'] ?? ''), $query);
            $id = isset($query['v']) ? (string) $query['v'] : '';
            if (self::isYouTubeId($id)) {
                return new OEmbedMatch(OEmbedMatch::PROVIDER_YOUTUBE, $id, $url);
            }
        }

        return null;
    }

    private static function parseTwitter(string $url): ?OEmbedMatch
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'])) {
            return null;
        }
        $host = strtolower((string) $parts['host']);
        if (!in_array($host, ['twitter.com', 'www.twitter.com', 'mobile.twitter.com', 'x.com', 'www.x.com', 'mobile.x.com'], true)) {
            return null;
        }
        $path = (string) ($parts['path'] ?? '');
        if (preg_match('#/status/(\d+)#', $path, $m) !== 1) {
            return null;
        }

        return new OEmbedMatch(OEmbedMatch::PROVIDER_TWITTER, $m[1], $url);
    }

    private static function isYouTubeId(string $id): bool
    {
        return $id !== '' && preg_match('/^[\w-]{11}$/', $id) === 1;
    }
}
