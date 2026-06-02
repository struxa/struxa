<?php

declare(strict_types=1);

namespace App\Dist;

use App\Cache\FileCache;
use App\CmsVersion;

/**
 * Live GitHub release version + distribution catalog counts for the storefront GitHub showcase.
 */
final class GithubShowcaseStats
{
    private const CACHE_KEY_PREFIX = 'github_showcase_stats_v2_';

    private const CACHE_TTL = 3600;

    private const MAX_BYTES = 131_072;

    public function __construct(
        private readonly string $projectRoot,
        private readonly FileCache $cache,
    ) {
    }

    /**
     * @return array{
     *   ok: bool,
     *   repo: string,
     *   latest_version: ?string,
     *   release_url: ?string,
     *   themes_count: int,
     *   plugins_count: int,
     *   stars: ?int,
     *   error: ?string
     * }
     */
    public function forRepoUrl(string $repoUrl): array
    {
        $repo = self::normalizeRepoFromUrl($repoUrl);
        if ($repo === null) {
            return $this->emptyPack('', 'Invalid GitHub repository URL.');
        }

        $cacheKey = self::CACHE_KEY_PREFIX . hash('sha256', $repo);
        /** @var mixed $cached */
        $cached = $this->cache->get($cacheKey);
        if (is_array($cached) && isset($cached['repo']) && is_string($cached['repo']) && $cached['repo'] === $repo) {
            /** @var array{ok: bool, repo: string, latest_version: ?string, release_url: ?string, themes_count: int, plugins_count: int, stars: ?int, error: ?string} $cached */
            return $cached;
        }

        $catalogCounts = (new StruxaDistCatalogClient($this->projectRoot))->loadShowcaseCounts();
        $themesCount = $catalogCounts['themes'];
        $pluginsCount = $catalogCounts['plugins'];

        $releaseUrl = 'https://github.com/' . $repo . '/releases/latest';
        $latest = null;
        $stars = null;
        $error = null;

        $repoMeta = $this->fetchJson('https://api.github.com/repos/' . $repo, true);
        if (is_array($repoMeta)) {
            if (isset($repoMeta['stargazers_count']) && is_numeric($repoMeta['stargazers_count'])) {
                $stars = (int) $repoMeta['stargazers_count'];
            }
        }

        $release = $this->fetchJson('https://api.github.com/repos/' . $repo . '/releases/latest', true);
        if (is_array($release) && isset($release['tag_name']) && is_string($release['tag_name'])) {
            $latest = self::normalizeReleaseTag($release['tag_name']);
            if (isset($release['html_url']) && is_string($release['html_url']) && str_starts_with($release['html_url'], 'https://')) {
                $releaseUrl = $release['html_url'];
            }
        }

        if ($latest === null || !self::isReasonableSemver($latest)) {
            $ref = trim((string) ($_ENV['STRUXA_UPDATES_GITHUB_REF'] ?? getenv('STRUXA_UPDATES_GITHUB_REF') ?: 'main'));
            if ($ref === '') {
                $ref = 'main';
            }
            $composerRaw = $this->httpGet(
                'https://raw.githubusercontent.com/' . $repo . '/' . rawurlencode($ref) . '/composer.json',
                false,
            );
            if ($composerRaw !== null && $composerRaw !== '') {
                try {
                    /** @var mixed $composer */
                    $composer = json_decode($composerRaw, true, 32, JSON_THROW_ON_ERROR);
                    if (is_array($composer) && isset($composer['version']) && is_string($composer['version'])) {
                        $ver = trim($composer['version']);
                        if (self::isReasonableSemver($ver)) {
                            $latest = $ver;
                        }
                    }
                } catch (\JsonException) {
                    // fall through
                }
            }
        }

        if ($latest === null) {
            $latest = self::isReasonableSemver(CmsVersion::CURRENT) ? CmsVersion::CURRENT : null;
            $error = 'Could not read latest release from GitHub.';
        }

        $pack = [
            'ok' => $latest !== null,
            'repo' => $repo,
            'latest_version' => $latest,
            'release_url' => $releaseUrl,
            'themes_count' => $themesCount,
            'plugins_count' => $pluginsCount,
            'stars' => $stars,
            'error' => $error,
        ];
        $this->cache->set($cacheKey, $pack, self::CACHE_TTL);

        return $pack;
    }

    public static function normalizeRepoFromUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        $url = preg_replace('#\.git$#i', '', $url) ?? $url;
        if (preg_match('~github\.com/([^/]+)/([^/?#]+)~i', $url, $m) !== 1) {
            return null;
        }
        $owner = trim($m[1]);
        $name = trim($m[2]);
        if ($owner === '' || $name === '' || !preg_match('~^[a-zA-Z0-9_.-]+/[a-zA-Z0-9_.-]+$~', $owner . '/' . $name)) {
            return null;
        }

        return $owner . '/' . $name;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchJson(string $url, bool $githubApi): ?array
    {
        $raw = $this->httpGet($url, $githubApi);
        if ($raw === null || $raw === '') {
            return null;
        }
        try {
            /** @var mixed $data */
            $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($data) ? $data : null;
    }

    private function httpGet(string $url, bool $githubApi): ?string
    {
        if (!str_starts_with($url, 'https://')) {
            return null;
        }
        $accept = $githubApi ? 'application/vnd.github+json' : 'application/json';
        $ua = 'Struxa-GithubShowcase/1.0 (+https://struxapoint.com)';

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                return null;
            }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_TIMEOUT => 12,
                CURLOPT_HTTPHEADER => [
                    'Accept: ' . $accept,
                    'User-Agent: ' . $ua,
                ],
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            if (!is_string($body) || $code < 200 || $code >= 300) {
                return null;
            }
            if (strlen($body) > self::MAX_BYTES) {
                $body = substr($body, 0, self::MAX_BYTES);
            }

            return $body;
        }

        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 12,
                'header' => "Accept: {$accept}\r\nUser-Agent: {$ua}\r\n",
            ],
        ]);
        $body = @file_get_contents($url, false, $ctx);

        return is_string($body) && $body !== '' ? $body : null;
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

    private static function isReasonableSemver(string $v): bool
    {
        return preg_match('/^\d+\.\d+\.\d+([.-][0-9A-Za-z.-]+)?$/', $v) === 1;
    }

    /**
     * @return array{ok: bool, repo: string, latest_version: ?string, release_url: ?string, themes_count: int, plugins_count: int, stars: ?int, error: ?string}
     */
    private function emptyPack(string $repo, string $error): array
    {
        $counts = (new StruxaDistCatalogClient($this->projectRoot))->loadShowcaseCounts();

        return [
            'ok' => false,
            'repo' => $repo,
            'latest_version' => null,
            'release_url' => null,
            'themes_count' => $counts['themes'],
            'plugins_count' => $counts['plugins'],
            'stars' => null,
            'error' => $error,
        ];
    }
}
