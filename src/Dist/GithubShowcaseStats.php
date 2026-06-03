<?php

declare(strict_types=1);

namespace App\Dist;

use App\Cache\FileCache;
use App\CmsVersion;
use App\Update\CmsUpdateChecker;

/**
 * CMS release version (updates feed) + distribution catalog counts + GitHub stars for the storefront showcase.
 */
final class GithubShowcaseStats
{
    private const CACHE_KEY_PREFIX = 'github_showcase_stats_v5_';

    private const CACHE_TTL = 3600;

    private const MAX_BYTES = 131_072;

    /** Rough bytes-per-line by GitHub language name for LOC estimation. */
    private const LANGUAGE_BYTES_PER_LINE = [
        'PHP' => 45,
        'Twig' => 38,
        'JavaScript' => 42,
        'TypeScript' => 45,
        'CSS' => 55,
        'HTML' => 50,
        'Shell' => 40,
        'Dockerfile' => 35,
        'SQL' => 42,
        'JSON' => 30,
        'Markdown' => 50,
    ];

    private const DEFAULT_BYTES_PER_LINE = 45;

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
     *   lines_of_code: ?int,
     *   lines_of_code_label: ?string,
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

        $catalogCounts = (new StruxaDistCatalogClient($this->projectRoot))->loadShowcaseCounts();
        $themesCount = $catalogCounts['themes'];
        $pluginsCount = $catalogCounts['plugins'];
        $catalogStamp = $catalogCounts['generated_at'] ?? '';

        $updates = (new CmsUpdateChecker($this->cache))->check();
        $latest = null;
        $releaseUrl = 'https://struxapoint.com/releases';
        if (($updates['ok'] ?? false) && isset($updates['latest_version']) && is_string($updates['latest_version'])) {
            $ver = trim($updates['latest_version']);
            if ($ver !== '' && self::isReasonableSemver($ver)) {
                $latest = $ver;
            }
        }
        if (isset($updates['release_url']) && is_string($updates['release_url'])) {
            $url = trim($updates['release_url']);
            if ($url !== '' && str_starts_with($url, 'https://')) {
                $releaseUrl = $url;
            }
        }

        $cacheKey = self::CACHE_KEY_PREFIX . hash('sha256', $repo . '|' . $catalogStamp . '|' . $themesCount . '|' . $pluginsCount . '|' . ($latest ?? ''));

        /** @var mixed $cached */
        $cached = $this->cache->get($cacheKey);
        if (is_array($cached) && isset($cached['repo']) && is_string($cached['repo']) && $cached['repo'] === $repo) {
            /** @var array{ok: bool, repo: string, latest_version: ?string, release_url: ?string, themes_count: int, plugins_count: int, lines_of_code: ?int, lines_of_code_label: ?string, stars: ?int, error: ?string} $cached */
            return $cached;
        }

        $stars = null;
        $error = null;

        $repoMeta = $this->fetchJson('https://api.github.com/repos/' . $repo, true);
        if (is_array($repoMeta) && isset($repoMeta['stargazers_count']) && is_numeric($repoMeta['stargazers_count'])) {
            $stars = (int) $repoMeta['stargazers_count'];
        }

        $linesOfCode = $this->resolveLinesOfCode($repo);
        $linesLabel = self::formatLinesOfCode($linesOfCode);

        if ($latest === null) {
            $latest = self::isReasonableSemver(CmsVersion::CURRENT) ? CmsVersion::CURRENT : null;
            if ($latest === null && isset($updates['error']) && is_string($updates['error'])) {
                $error = $updates['error'];
            } elseif ($latest === null) {
                $error = 'Could not read latest CMS version from the updates feed.';
            }
        }

        $pack = [
            'ok' => $latest !== null,
            'repo' => $repo,
            'latest_version' => $latest,
            'release_url' => $releaseUrl,
            'themes_count' => $themesCount,
            'plugins_count' => $pluginsCount,
            'lines_of_code' => $linesOfCode,
            'lines_of_code_label' => $linesLabel,
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

    private static function isReasonableSemver(string $v): bool
    {
        return preg_match('/^\d+\.\d+\.\d+([.-][0-9A-Za-z.-]+)?$/', $v) === 1;
    }

    private function resolveLinesOfCode(string $repo): ?int
    {
        $override = trim((string) ($_ENV['STRUXA_SHOWCASE_LINES_OF_CODE'] ?? getenv('STRUXA_SHOWCASE_LINES_OF_CODE') ?: ''));
        if ($override !== '' && preg_match('/^\d+$/', $override) === 1) {
            return max(0, (int) $override);
        }

        return $this->estimateLinesFromGitHubLanguages($repo);
    }

    private function estimateLinesFromGitHubLanguages(string $repo): ?int
    {
        $languages = $this->fetchJson('https://api.github.com/repos/' . $repo . '/languages', true);
        if ($languages === null || $languages === []) {
            return null;
        }

        $totalLines = 0;
        foreach ($languages as $language => $bytes) {
            if (!is_string($language) || !is_numeric($bytes)) {
                continue;
            }
            $byteCount = (int) $bytes;
            if ($byteCount < 1) {
                continue;
            }
            $divisor = self::LANGUAGE_BYTES_PER_LINE[$language] ?? self::DEFAULT_BYTES_PER_LINE;
            $totalLines += (int) round($byteCount / $divisor);
        }

        return $totalLines > 0 ? $totalLines : null;
    }

    public static function formatLinesOfCode(?int $lines): ?string
    {
        if ($lines === null || $lines < 1) {
            return null;
        }
        if ($lines >= 1_000_000) {
            $millions = $lines / 1_000_000;

            return ($millions >= 10.0 ? (string) (int) round($millions) : rtrim(rtrim(number_format($millions, 1, '.', ''), '0'), '.')) . 'M+';
        }
        if ($lines >= 10_000) {
            return (string) (int) round($lines / 1000) . 'k+';
        }
        if ($lines >= 1000) {
            return rtrim(rtrim(number_format($lines / 1000, 1, '.', ''), '0'), '.') . 'k+';
        }

        return number_format($lines) . '+';
    }

    /**
     * @return array{ok: bool, repo: string, latest_version: ?string, release_url: ?string, themes_count: int, plugins_count: int, lines_of_code: ?int, lines_of_code_label: ?string, stars: ?int, error: ?string}
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
            'lines_of_code' => null,
            'lines_of_code_label' => null,
            'stars' => null,
            'error' => $error,
        ];
    }
}
