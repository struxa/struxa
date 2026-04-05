<?php

declare(strict_types=1);

namespace App\Update;

use App\Cache\FileCache;
use App\CmsVersion;

/**
 * Fetches semver CMS update metadata from a remote JSON feed (cached).
 *
 * @phpstan-type UpdateStatus array{
 *   ok: bool,
 *   update_available: bool,
 *   current_version: string,
 *   latest_version?: string,
 *   title?: string,
 *   summary?: string,
 *   release_url?: string,
 *   severity?: string,
 *   error?: string,
 *   fetched_at: int,
 *   feed_url: string
 * }
 */
final class CmsUpdateChecker
{
    private const CACHE_KEY = 'cms_remote_updates_v1';

    private const CACHE_TTL = 3600;

    private const MAX_BYTES = 32_768;

    /** Default public feed; override with STRUXA_UPDATES_JSON_URL. */
    private const DEFAULT_FEED_URL = 'https://struxapoint.com/theme-repo/updates.json';

    public function __construct(
        private readonly FileCache $internalCache,
    ) {
    }

    /**
     * @return UpdateStatus
     */
    public function check(bool $forceRefresh = false): array
    {
        $feedUrl = $this->feedUrl();
        $base = [
            'ok' => false,
            'update_available' => false,
            'current_version' => CmsVersion::CURRENT,
            'fetched_at' => time(),
            'feed_url' => $feedUrl,
        ];

        if (!$forceRefresh) {
            /** @var mixed $cached */
            $cached = $this->internalCache->get(self::CACHE_KEY);
            if (is_array($cached) && isset($cached['current_version'], $cached['fetched_at'], $cached['feed_url'])) {
                /** @var UpdateStatus $cached */
                return $cached;
            }
        }

        $raw = $this->httpGetLimited($feedUrl, self::MAX_BYTES);
        if ($raw === null || $raw === '') {
            $out = array_merge($base, [
                'ok' => false,
                'error' => 'Could not download the updates feed.',
            ]);
            $this->internalCache->set(self::CACHE_KEY, $out, self::CACHE_TTL);

            return $out;
        }

        try {
            /** @var mixed $data */
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $out = array_merge($base, [
                'ok' => false,
                'error' => 'Updates feed is not valid JSON.',
            ]);
            $this->internalCache->set(self::CACHE_KEY, $out, self::CACHE_TTL);

            return $out;
        }

        if (!is_array($data)) {
            $out = array_merge($base, [
                'ok' => false,
                'error' => 'Updates feed must be a JSON object.',
            ]);
            $this->internalCache->set(self::CACHE_KEY, $out, self::CACHE_TTL);

            return $out;
        }

        $schema = $data['schema_version'] ?? 1;
        if ((int) $schema !== 1) {
            $out = array_merge($base, [
                'ok' => false,
                'error' => 'Unsupported schema_version (expected 1).',
            ]);
            $this->internalCache->set(self::CACHE_KEY, $out, self::CACHE_TTL);

            return $out;
        }

        $cms = $data['cms'] ?? null;
        if (!is_array($cms)) {
            $out = array_merge($base, [
                'ok' => false,
                'error' => 'Missing "cms" object in updates feed.',
            ]);
            $this->internalCache->set(self::CACHE_KEY, $out, self::CACHE_TTL);

            return $out;
        }

        $latest = isset($cms['latest_version']) && is_string($cms['latest_version'])
            ? trim($cms['latest_version'])
            : '';
        if ($latest === '' || !self::isReasonableSemver($latest)) {
            $out = array_merge($base, [
                'ok' => false,
                'error' => 'Feed did not include a valid cms.latest_version.',
            ]);
            $this->internalCache->set(self::CACHE_KEY, $out, self::CACHE_TTL);

            return $out;
        }

        $current = CmsVersion::CURRENT;
        $available = version_compare($latest, $current, '>');

        $title = isset($cms['title']) && is_string($cms['title']) ? trim($cms['title']) : '';
        $summary = isset($cms['summary']) && is_string($cms['summary']) ? trim($cms['summary']) : '';
        $releaseUrl = isset($cms['release_url']) && is_string($cms['release_url']) ? trim($cms['release_url']) : '';
        $severity = isset($cms['severity']) && is_string($cms['severity']) ? trim(strtolower($cms['severity'])) : '';

        if ($releaseUrl !== '' && !str_starts_with($releaseUrl, 'https://')) {
            $releaseUrl = '';
        }

        $out = array_merge($base, [
            'ok' => true,
            'update_available' => $available,
            'latest_version' => $latest,
            'title' => $title,
            'summary' => $summary,
            'release_url' => $releaseUrl,
            'severity' => $severity,
        ]);
        unset($out['error']);
        $this->internalCache->set(self::CACHE_KEY, $out, self::CACHE_TTL);

        return $out;
    }

    public function feedUrl(): string
    {
        $raw = trim((string) ($_ENV['STRUXA_UPDATES_JSON_URL'] ?? getenv('STRUXA_UPDATES_JSON_URL') ?? ''));
        if ($raw !== '') {
            return $raw;
        }

        return self::DEFAULT_FEED_URL;
    }

    private static function isReasonableSemver(string $v): bool
    {
        return preg_match('/^\d+\.\d+\.\d+([.-][0-9A-Za-z.-]+)?$/', $v) === 1;
    }

    private function httpGetLimited(string $url, int $maxBytes): ?string
    {
        if (!str_starts_with($url, 'https://')) {
            return null;
        }

        $ctx = stream_context_create([
            'http' => [
                'timeout' => 15,
                'follow_location' => 1,
                'max_redirects' => 5,
                'header' => "User-Agent: Struxa-CmsUpdateCheck/1.0\r\nAccept: application/json\r\n",
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);
        $h = @fopen($url, 'r', false, $ctx);
        if ($h === false) {
            return null;
        }
        $data = '';
        while (!feof($h) && strlen($data) < $maxBytes) {
            $chunk = fread($h, 8192);
            if ($chunk === false) {
                break;
            }
            $data .= $chunk;
        }
        fclose($h);
        if (strlen($data) >= $maxBytes) {
            return null;
        }

        return $data;
    }
}
