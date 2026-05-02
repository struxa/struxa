<?php

declare(strict_types=1);

namespace ContentStreamPlugin;

/**
 * Fetches the site homepage and reduces HTML to plain text for evidence extraction.
 */
final class HomepageTextFetcher
{
    private const MAX_BYTES = 524_288;
    private const MAX_TEXT_CHARS = 50_000;

    /**
     * @return array{ok: bool, final_url: string, text: string, error: string}
     */
    public static function fetch(string $domain): array
    {
        $domain = trim($domain);
        if ($domain === '' || $domain === 'localhost') {
            return ['ok' => false, 'final_url' => '', 'text' => '', 'error' => 'localhost_or_empty'];
        }

        $url = 'https://' . $domain . '/';
        $html = self::httpGet($url);
        if ($html === null) {
            $www = 'https://www.' . $domain . '/';
            $html = self::httpGet($www);
            if ($html !== null) {
                $url = $www;
            }
        }

        if ($html === null) {
            return ['ok' => false, 'final_url' => $url, 'text' => '', 'error' => 'fetch_failed'];
        }

        $text = self::htmlToPlainText($html);
        if (mb_strlen($text, 'UTF-8') > self::MAX_TEXT_CHARS) {
            $text = mb_substr($text, 0, self::MAX_TEXT_CHARS, 'UTF-8');
        }

        return ['ok' => true, 'final_url' => $url, 'text' => $text, 'error' => ''];
    }

    private static function httpGet(string $url): ?string
    {
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", [
                    'User-Agent: StruxaContentStream/1.0 (domain research; contact: admin)',
                    'Accept: text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
                    'Accept-Language: en-US,en;q=0.5',
                ]),
                'timeout' => 18,
                'follow_location' => 1,
                'max_redirects' => 6,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $raw = @file_get_contents($url, false, $ctx, 0, self::MAX_BYTES);
        if ($raw === false || $raw === '') {
            return null;
        }

        return $raw;
    }

    private static function htmlToPlainText(string $html): string
    {
        $html = preg_replace('#<script\b[^>]*>.*?</script>#is', ' ', $html) ?? $html;
        $html = preg_replace('#<style\b[^>]*>.*?</style>#is', ' ', $html) ?? $html;
        $html = preg_replace('#<noscript\b[^>]*>.*?</noscript>#is', ' ', $html) ?? $html;
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }
}
