<?php

declare(strict_types=1);

namespace App\Update;

use App\Cache\FileCache;
use App\CmsVersion;

/**
 * Fetches semver CMS update metadata from a remote JSON feed or GitHub (cached).
 *
 * @phpstan-type UpdateStatus array{
 *   ok: bool,
 *   update_available: bool,
 *   current_version: string,
 *   latest_version?: string,
 *   title?: string,
 *   summary?: string,
 *   release_url?: string,
 *   download_url?: string,
 *   severity?: string,
 *   error?: string,
 *   fetched_at: int,
 *   feed_url: string,
 *   source?: string
 * }
 */
final class CmsUpdateChecker
{
    private const CACHE_KEY_FEED = 'cms_remote_updates_v1';

    private const CACHE_KEY_GITHUB_PREFIX = 'cms_remote_updates_gh_v1_';

    private const CACHE_TTL = 3600;

    /** Re-fetch remote feed when cache is older than this (admin UI); keeps new releases visible without opening Updates. */
    private const ADMIN_UI_MAX_CACHE_AGE_SEC = 900;

    private const MAX_BYTES = 32_768;

    private const MAX_BYTES_GITHUB_API = 262_144;

    /** Default public feed (PHP generator or static .json); override with STRUXA_UPDATES_JSON_URL. */
    private const DEFAULT_FEED_URL = 'https://struxapoint.com/dist/struxa-updates-json.php';

    public function __construct(
        private readonly FileCache $internalCache,
    ) {
    }

    /**
     * @return UpdateStatus
     */
    public function check(bool $forceRefresh = false): array
    {
        $githubRepo = self::normalizeGithubRepo(self::envString('STRUXA_UPDATES_GITHUB_REPO'));
        if ($githubRepo !== '') {
            return $this->checkGithub($githubRepo, $forceRefresh);
        }

        return $this->checkJsonFeed($forceRefresh);
    }

    /**
     * For admin layout: use fresh feed when cache is stale so the bell/banner reflect new releases promptly.
     *
     * @return UpdateStatus
     */
    public function checkForAdminUi(): array
    {
        $key = $this->primaryCacheKey();
        $cached = $this->internalCache->get($key);
        if (is_array($cached) && isset($cached['fetched_at'])) {
            $age = time() - (int) $cached['fetched_at'];
            if ($age >= 0 && $age <= self::ADMIN_UI_MAX_CACHE_AGE_SEC) {
                return $this->reconcileCachedStatus($key, $cached);
            }
        }

        return $this->check(true);
    }

    private function primaryCacheKey(): string
    {
        $githubRepo = self::normalizeGithubRepo(self::envString('STRUXA_UPDATES_GITHUB_REPO'));
        if ($githubRepo !== '') {
            return self::CACHE_KEY_GITHUB_PREFIX . hash('sha256', $githubRepo);
        }

        return self::CACHE_KEY_FEED;
    }

    /**
     * @return UpdateStatus
     */
    private function checkJsonFeed(bool $forceRefresh): array
    {
        $feedUrl = $this->jsonFeedUrl();
        $base = [
            'ok' => false,
            'update_available' => false,
            'current_version' => CmsVersion::CURRENT,
            'fetched_at' => time(),
            'feed_url' => $feedUrl,
            'source' => 'feed',
        ];

        if (!$forceRefresh) {
            /** @var mixed $cached */
            $cached = $this->internalCache->get(self::CACHE_KEY_FEED);
            if (is_array($cached) && isset($cached['current_version'], $cached['fetched_at'], $cached['feed_url'])) {
                /** @var UpdateStatus $cached */
                return $this->reconcileCachedStatus(self::CACHE_KEY_FEED, $cached);
            }
        }

        $raw = $this->fetchJsonFeedBody($feedUrl);
        if ($raw === null || $raw === '') {
            $out = array_merge($base, [
                'ok' => false,
                'error' => 'Could not download the updates feed.',
            ]);
            $this->internalCache->set(self::CACHE_KEY_FEED, $out, self::CACHE_TTL);

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
            $this->internalCache->set(self::CACHE_KEY_FEED, $out, self::CACHE_TTL);

            return $out;
        }

        if (!is_array($data)) {
            $out = array_merge($base, [
                'ok' => false,
                'error' => 'Updates feed must be a JSON object.',
            ]);
            $this->internalCache->set(self::CACHE_KEY_FEED, $out, self::CACHE_TTL);

            return $out;
        }

        $schema = $data['schema_version'] ?? 1;
        if ((int) $schema !== 1) {
            $out = array_merge($base, [
                'ok' => false,
                'error' => 'Unsupported schema_version (expected 1).',
            ]);
            $this->internalCache->set(self::CACHE_KEY_FEED, $out, self::CACHE_TTL);

            return $out;
        }

        $cms = $data['cms'] ?? null;
        if (!is_array($cms)) {
            $out = array_merge($base, [
                'ok' => false,
                'error' => 'Missing "cms" object in updates feed.',
            ]);
            $this->internalCache->set(self::CACHE_KEY_FEED, $out, self::CACHE_TTL);

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
            $this->internalCache->set(self::CACHE_KEY_FEED, $out, self::CACHE_TTL);

            return $out;
        }

        $current = CmsVersion::CURRENT;
        $available = version_compare($latest, $current, '>');

        $title = isset($cms['title']) && is_string($cms['title']) ? trim($cms['title']) : '';
        $summary = isset($cms['summary']) && is_string($cms['summary']) ? trim($cms['summary']) : '';
        $releaseUrl = isset($cms['release_url']) && is_string($cms['release_url']) ? trim($cms['release_url']) : '';
        $downloadUrl = isset($cms['download_url']) && is_string($cms['download_url']) ? trim($cms['download_url']) : '';
        $severity = isset($cms['severity']) && is_string($cms['severity']) ? trim(strtolower($cms['severity'])) : '';

        if ($releaseUrl !== '' && !str_starts_with($releaseUrl, 'https://')) {
            $releaseUrl = '';
        }
        if ($downloadUrl !== '' && !str_starts_with($downloadUrl, 'https://')) {
            $downloadUrl = '';
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
        if ($downloadUrl !== '') {
            $out['download_url'] = $downloadUrl;
        }
        $this->internalCache->set(self::CACHE_KEY_FEED, $out, self::CACHE_TTL);

        return $out;
    }

    /**
     * @return UpdateStatus
     */
    private function checkGithub(string $repo, bool $forceRefresh): array
    {
        $cacheKey = self::CACHE_KEY_GITHUB_PREFIX . hash('sha256', $repo);
        $feedDisplay = 'https://github.com/' . $repo . '/releases/latest';
        $base = [
            'ok' => false,
            'update_available' => false,
            'current_version' => CmsVersion::CURRENT,
            'fetched_at' => time(),
            'feed_url' => $feedDisplay,
            'source' => 'github',
        ];

        if (!$forceRefresh) {
            /** @var mixed $cached */
            $cached = $this->internalCache->get($cacheKey);
            if (is_array($cached) && isset($cached['current_version'], $cached['fetched_at'], $cached['feed_url'])) {
                /** @var UpdateStatus $cached */
                return $this->reconcileCachedStatus($cacheKey, $cached);
            }
        }

        $apiUrl = 'https://api.github.com/repos/' . $repo . '/releases/latest';
        $raw = $this->httpGetLimited($apiUrl, self::MAX_BYTES_GITHUB_API, true);
        if ($raw !== null && $raw !== '') {
            try {
                /** @var mixed $payload */
                $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $payload = null;
            }
            if (is_array($payload) && isset($payload['message']) && is_string($payload['message'])) {
                $msg = strtolower($payload['message']);
                if (str_contains($msg, 'rate limit')) {
                    $out = array_merge($base, [
                        'error' => 'GitHub API rate limit — try again in a few minutes or set STRUXA_UPDATES_JSON_URL instead.',
                    ]);
                    $this->internalCache->set($cacheKey, $out, self::CACHE_TTL);

                    return $out;
                }
            }
            if (is_array($payload) && isset($payload['tag_name']) && is_string($payload['tag_name'])) {
                $latest = self::normalizeReleaseTag($payload['tag_name']);
                if ($latest !== '' && self::isReasonableSemver($latest)) {
                    $out = $this->buildGithubSuccess($base, $cacheKey, $latest, $payload, $repo);

                    return $out;
                }
            }
        }

        $ref = self::githubRef();
        $composerUrl = 'https://raw.githubusercontent.com/' . $repo . '/' . $ref . '/composer.json';
        $cRaw = $this->httpGetLimited($composerUrl, self::MAX_BYTES, false);
        if ($cRaw === null || $cRaw === '') {
            $out = array_merge($base, [
                'error' => 'Could not read latest GitHub Release or composer.json on branch ' . $ref . '.',
            ]);
            $this->internalCache->set($cacheKey, $out, self::CACHE_TTL);

            return $out;
        }

        try {
            /** @var mixed $composer */
            $composer = json_decode($cRaw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $out = array_merge($base, [
                'error' => 'composer.json on GitHub is not valid JSON.',
            ]);
            $this->internalCache->set($cacheKey, $out, self::CACHE_TTL);

            return $out;
        }

        $ver = is_array($composer) && isset($composer['version']) && is_string($composer['version'])
            ? trim($composer['version'])
            : '';
        if ($ver === '' || !self::isReasonableSemver($ver)) {
            $out = array_merge($base, [
                'error' => 'composer.json on GitHub did not include a valid version.',
            ]);
            $this->internalCache->set($cacheKey, $out, self::CACHE_TTL);

            return $out;
        }

        $current = CmsVersion::CURRENT;
        $available = version_compare($ver, $current, '>');
        $zipUrl = 'https://github.com/' . $repo . '/archive/refs/heads/' . rawurlencode($ref) . '.zip';
        $out = array_merge($base, [
            'ok' => true,
            'update_available' => $available,
            'latest_version' => $ver,
            'title' => 'Struxa ' . $ver,
            'summary' => 'No published GitHub Release yet; version is taken from composer.json on branch ' . $ref . '.',
            'release_url' => 'https://github.com/' . $repo,
            'download_url' => $zipUrl,
            'severity' => 'minor',
        ]);
        $this->internalCache->set($cacheKey, $out, self::CACHE_TTL);

        return $out;
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $payload
     * @return UpdateStatus
     */
    private function buildGithubSuccess(array $base, string $cacheKey, string $latest, array $payload, string $repo): array
    {
        $current = CmsVersion::CURRENT;
        $available = version_compare($latest, $current, '>');
        $name = isset($payload['name']) && is_string($payload['name']) ? trim($payload['name']) : '';
        $title = $name !== '' ? $name : ('Struxa ' . $latest);
        $body = isset($payload['body']) && is_string($payload['body']) ? trim($payload['body']) : '';
        $summary = $body !== '' ? self::truncateSummary($body, 480) : '';
        $htmlUrl = isset($payload['html_url']) && is_string($payload['html_url']) ? trim($payload['html_url']) : '';
        if ($htmlUrl !== '' && !str_starts_with($htmlUrl, 'https://')) {
            $htmlUrl = '';
        }
        $zipball = isset($payload['zipball_url']) && is_string($payload['zipball_url']) ? trim($payload['zipball_url']) : '';
        if ($zipball !== '' && !str_starts_with($zipball, 'https://')) {
            $zipball = '';
        }
        if ($zipball === '') {
            $tag = isset($payload['tag_name']) && is_string($payload['tag_name']) ? trim($payload['tag_name']) : '';
            if ($tag !== '') {
                $zipball = 'https://github.com/' . $repo . '/archive/refs/tags/' . rawurlencode($tag) . '.zip';
            }
        }

        $out = array_merge($base, [
            'ok' => true,
            'update_available' => $available,
            'latest_version' => $latest,
            'title' => $title,
            'summary' => $summary,
            'release_url' => $htmlUrl,
            'severity' => 'minor',
        ]);
        if ($zipball !== '') {
            $out['download_url'] = $zipball;
        }
        $this->internalCache->set($cacheKey, $out, self::CACHE_TTL);

        return $out;
    }

    /**
     * When the install was upgraded without clearing cache, refresh current_version and update_available from cache + CmsVersion::CURRENT.
     *
     * @param array<string, mixed> $cached
     * @return UpdateStatus
     */
    private function reconcileCachedStatus(string $persistKey, array $cached): array
    {
        $installed = CmsVersion::CURRENT;
        $cachedCv = isset($cached['current_version']) && is_string($cached['current_version'])
            ? trim($cached['current_version'])
            : '';
        if ($cachedCv === $installed) {
            /** @var UpdateStatus $cached */
            return $cached;
        }
        $cached['current_version'] = $installed;
        if (!empty($cached['ok']) && isset($cached['latest_version']) && is_string($cached['latest_version'])) {
            $latest = trim($cached['latest_version']);
            if ($latest !== '' && self::isReasonableSemver($latest)) {
                $cached['update_available'] = version_compare($latest, $installed, '>');
            }
        }
        $this->internalCache->set($persistKey, $cached, self::CACHE_TTL);
        /** @var UpdateStatus $cached */
        return $cached;
    }

    public function feedUrl(): string
    {
        $githubRepo = self::normalizeGithubRepo(self::envString('STRUXA_UPDATES_GITHUB_REPO'));
        if ($githubRepo !== '') {
            return 'https://github.com/' . $githubRepo . '/releases/latest';
        }

        return $this->jsonFeedUrl();
    }

    private function jsonFeedUrl(): string
    {
        $raw = self::envString('STRUXA_UPDATES_JSON_URL');
        if ($raw !== '') {
            return $raw;
        }

        return self::DEFAULT_FEED_URL;
    }

    /**
     * Load feed: optional local file (hairpin/NAT-safe) then HTTPS GET with cURL → fopen fallback.
     */
    private function fetchJsonFeedBody(string $feedUrl): ?string
    {
        $localPath = self::envString('STRUXA_UPDATES_JSON_PATH');
        if ($localPath !== '') {
            $fromFile = $this->readLocalUpdatesFile($localPath);
            if ($fromFile !== null) {
                return $fromFile;
            }
        }

        return $this->httpGetLimited($feedUrl, self::MAX_BYTES, false);
    }

    /**
     * Read updates JSON from disk (.env STRUXA_UPDATES_JSON_PATH). Use when the server cannot HTTP-fetch its own public URL.
     *
     * @return non-empty-string|null
     */
    private function readLocalUpdatesFile(string $path): ?string
    {
        $path = trim($path);
        if ($path === '' || str_contains($path, "\0")) {
            return null;
        }
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }
        $size = filesize($path);
        if ($size === false || $size < 1 || $size > self::MAX_BYTES) {
            return null;
        }
        $raw = file_get_contents($path, false, null, 0, self::MAX_BYTES + 1);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        if (strlen($raw) > self::MAX_BYTES) {
            return null;
        }

        return $raw;
    }

    private static function envString(string $key, string $default = ''): string
    {
        if (isset($_ENV[$key])) {
            return trim((string) $_ENV[$key]);
        }
        $g = getenv($key);
        if ($g === false) {
            return $default;
        }

        return trim($g);
    }

    private static function githubRef(): string
    {
        $ref = self::envString('STRUXA_UPDATES_GITHUB_REF', 'main');
        if ($ref === '' || strlen($ref) > 200 || !preg_match('#^[a-zA-Z0-9._/-]+$#', $ref)) {
            return 'main';
        }

        return $ref;
    }

    private static function normalizeGithubRepo(string $raw): string
    {
        if ($raw === '' || strlen($raw) > 200) {
            return '';
        }
        if (!preg_match('#^[a-zA-Z0-9_.-]+/[a-zA-Z0-9_.-]+$#', $raw)) {
            return '';
        }

        return $raw;
    }

    private static function normalizeReleaseTag(string $tag): string
    {
        $tag = trim($tag);
        if ($tag === '') {
            return '';
        }
        if (preg_match('/^v\d/', $tag) === 1) {
            return substr($tag, 1);
        }

        return $tag;
    }

    private static function truncateSummary(string $text, int $max): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', $text);
        $text = trim(is_string($collapsed) ? $collapsed : $text);
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($text) <= $max) {
                return $text;
            }

            return mb_substr($text, 0, $max) . '…';
        }
        if (strlen($text) <= $max) {
            return $text;
        }

        return substr($text, 0, $max) . '…';
    }

    private static function isReasonableSemver(string $v): bool
    {
        return preg_match('/^\d+\.\d+\.\d+([.-][0-9A-Za-z.-]+)?$/', $v) === 1;
    }

    private function httpGetLimited(string $url, int $maxBytes, bool $githubApi): ?string
    {
        if (!str_starts_with($url, 'https://')) {
            return null;
        }

        $accept = $githubApi ? 'application/vnd.github+json' : 'application/json';
        $userAgents = [
            'Struxa-CmsUpdateCheck/1.0 (+https://struxapoint.com)',
            'Mozilla/5.0 (compatible; StruxaCMS/' . CmsVersion::CURRENT . '; +https://struxapoint.com) update-check',
        ];

        foreach ($userAgents as $ua) {
            if (function_exists('curl_init')) {
                $data = $this->httpGetCurl($url, $maxBytes, $ua, $accept, $githubApi);
                if ($data !== null) {
                    return $data;
                }
            }
            $data = $this->httpGetFopen($url, $maxBytes, $ua, $accept);
            if ($data !== null) {
                return $data;
            }
        }

        return null;
    }

    private function httpGetCurl(string $url, int $maxBytes, string $ua, string $accept, bool $githubApi): ?string
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        $headers = [
            'Accept: ' . $accept,
        ];
        if ($githubApi) {
            $headers[] = 'X-GitHub-Api-Version: 2022-11-28';
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_USERAGENT => $ua,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        if (defined('CURLOPT_MAXFILESIZE')) {
            curl_setopt($ch, CURLOPT_MAXFILESIZE, $maxBytes + 1);
        }
        $data = curl_exec($ch);
        $errno = curl_errno($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($errno !== 0 || !is_string($data)) {
            return null;
        }
        if ($code < 200 || $code >= 300) {
            return null;
        }
        if ($data === '' || strlen($data) > $maxBytes) {
            return null;
        }

        return $data;
    }

    private function httpGetFopen(string $url, int $maxBytes, string $ua, string $accept): ?string
    {
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 20,
                'follow_location' => 1,
                'max_redirects' => 5,
                'header' => "User-Agent: {$ua}\r\nAccept: {$accept}\r\n",
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
        $statusLine = $http_response_header[0] ?? '';
        if (!preg_match('#\s2\d\d\s#', $statusLine)) {
            fclose($h);

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
        if ($data === '' || strlen($data) > $maxBytes) {
            return null;
        }

        return $data;
    }
}
