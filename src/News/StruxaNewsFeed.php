<?php

declare(strict_types=1);

namespace App\News;

use App\Cache\FileCache;

/**
 * Fetches Struxa ecosystem news from struxapoint (cached JSON feed).
 *
 * @phpstan-type NewsItem array{
 *   id: string,
 *   title: string,
 *   summary: string,
 *   url: string,
 *   published_at: string,
 *   category: string
 * }
 * @phpstan-type NewsFeedStatus array{
 *   ok: bool,
 *   items: list<NewsItem>,
 *   error?: string,
 *   feed_url: string,
 *   fetched_at: int,
 *   skipped?: bool
 * }
 */
final class StruxaNewsFeed
{
    private const CACHE_KEY = 'struxa_news_feed_v1';

    private const CACHE_TTL = 3600;

    private const ADMIN_UI_MAX_CACHE_AGE_SEC = 900;

    private const MAX_BYTES = 65_536;

    private const MAX_ITEMS = 12;

    private const DEFAULT_FEED_URL = 'https://struxapoint.com/struxa-dist/news.json';

    public function __construct(
        private readonly FileCache $internalCache,
    ) {
    }

    /**
     * @return NewsFeedStatus
     */
    public function fetchForAdminUi(): array
    {
        if ($this->isDisabled()) {
            return [
                'ok' => true,
                'skipped' => true,
                'items' => [],
                'feed_url' => '',
                'fetched_at' => time(),
            ];
        }

        $cached = $this->internalCache->get(self::CACHE_KEY);
        if (is_array($cached) && isset($cached['fetched_at'], $cached['items'])) {
            $age = time() - (int) $cached['fetched_at'];
            if ($age >= 0 && $age <= self::ADMIN_UI_MAX_CACHE_AGE_SEC) {
                /** @var NewsFeedStatus $cached */
                return $cached;
            }
        }

        return $this->fetch(true);
    }

    /**
     * @return NewsFeedStatus
     */
    public function fetch(bool $forceRefresh = false): array
    {
        $feedUrl = $this->feedUrl();
        $base = [
            'ok' => false,
            'items' => [],
            'feed_url' => $feedUrl,
            'fetched_at' => time(),
        ];

        if ($this->isDisabled()) {
            return array_merge($base, ['ok' => true, 'skipped' => true]);
        }

        if (!$forceRefresh) {
            $cached = $this->internalCache->get(self::CACHE_KEY);
            if (is_array($cached) && isset($cached['fetched_at'], $cached['items'])) {
                /** @var NewsFeedStatus $cached */
                return $cached;
            }
        }

        $raw = $this->readFeedBody($feedUrl);
        if ($raw === null || $raw === '') {
            $out = array_merge($base, ['error' => 'Could not download the news feed.']);
            $this->internalCache->set(self::CACHE_KEY, $out, self::CACHE_TTL);

            return $out;
        }

        try {
            /** @var mixed $data */
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $out = array_merge($base, ['error' => 'News feed is not valid JSON.']);
            $this->internalCache->set(self::CACHE_KEY, $out, self::CACHE_TTL);

            return $out;
        }

        if (!is_array($data)) {
            $out = array_merge($base, ['error' => 'News feed must be a JSON object.']);
            $this->internalCache->set(self::CACHE_KEY, $out, self::CACHE_TTL);

            return $out;
        }

        if ((int) ($data['schema_version'] ?? 0) !== 1) {
            $out = array_merge($base, ['error' => 'Unsupported news schema_version (expected 1).']);
            $this->internalCache->set(self::CACHE_KEY, $out, self::CACHE_TTL);

            return $out;
        }

        $items = [];
        foreach ($data['items'] ?? [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $parsed = $this->parseItem($row);
            if ($parsed !== null) {
                $items[] = $parsed;
            }
            if (count($items) >= self::MAX_ITEMS) {
                break;
            }
        }

        usort($items, static function (array $a, array $b): int {
            return strcmp($b['published_at'], $a['published_at']);
        });

        $out = array_merge($base, ['ok' => true, 'items' => $items]);
        $this->internalCache->set(self::CACHE_KEY, $out, self::CACHE_TTL);

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return NewsItem|null
     */
    private function parseItem(array $row): ?array
    {
        $title = isset($row['title']) && is_string($row['title']) ? trim($row['title']) : '';
        if ($title === '') {
            return null;
        }

        $id = isset($row['id']) && is_string($row['id']) && trim($row['id']) !== ''
            ? trim($row['id'])
            : hash('sha256', $title . ($row['published_at'] ?? ''));

        $summary = isset($row['summary']) && is_string($row['summary']) ? trim($row['summary']) : '';
        $url = isset($row['url']) && is_string($row['url']) ? trim($row['url']) : '';
        if ($url !== '' && !str_starts_with($url, 'https://')) {
            $url = '';
        }

        $publishedAt = isset($row['published_at']) && is_string($row['published_at'])
            ? trim($row['published_at'])
            : gmdate('c');

        $category = isset($row['category']) && is_string($row['category'])
            ? strtolower(trim($row['category']))
            : 'news';
        if (!preg_match('/^[a-z0-9_-]+$/', $category)) {
            $category = 'news';
        }

        return [
            'id' => $id,
            'title' => $title,
            'summary' => $summary,
            'url' => $url,
            'published_at' => $publishedAt,
            'category' => $category,
        ];
    }

    private function feedUrl(): string
    {
        $local = self::envString('STRUXA_NEWS_JSON_PATH');
        if ($local !== '' && is_readable($local)) {
            return 'file://' . $local;
        }

        $url = self::envString('STRUXA_NEWS_JSON_URL');

        return $url !== '' ? $url : self::DEFAULT_FEED_URL;
    }

    private function isDisabled(): bool
    {
        $v = strtolower(self::envString('STRUXA_NEWS_DISABLED'));

        return in_array($v, ['1', 'true', 'yes', 'on'], true);
    }

    private function readFeedBody(string $feedUrl): ?string
    {
        if (str_starts_with($feedUrl, 'file://')) {
            $path = substr($feedUrl, 7);

            return is_readable($path) ? (string) file_get_contents($path) : null;
        }

        if (!str_starts_with($feedUrl, 'https://')) {
            return null;
        }

        return $this->httpGetLimited($feedUrl);
    }

    private function httpGetLimited(string $url): ?string
    {
        $ua = 'Struxa-NewsFeed/1.0 (+https://struxapoint.com)';

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch !== false) {
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS => 5,
                    CURLOPT_CONNECTTIMEOUT => 10,
                    CURLOPT_TIMEOUT => 20,
                    CURLOPT_HTTPHEADER => ['Accept: application/json', 'User-Agent: ' . $ua],
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                ]);
                $raw = curl_exec($ch);
                $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                if (is_string($raw) && $code >= 200 && $code < 300 && strlen($raw) <= self::MAX_BYTES) {
                    return $raw;
                }
            }
        }

        $ctx = stream_context_create([
            'http' => [
                'timeout' => 15,
                'follow_location' => 1,
                'max_redirects' => 5,
                'header' => "Accept: application/json\r\nUser-Agent: {$ua}\r\n",
            ],
        ]);
        $raw = @file_get_contents($url, false, $ctx);

        return is_string($raw) && strlen($raw) <= self::MAX_BYTES ? $raw : null;
    }

    private static function envString(string $key): string
    {
        $v = $_ENV[$key] ?? getenv($key);

        return is_string($v) ? trim($v) : '';
    }
}
