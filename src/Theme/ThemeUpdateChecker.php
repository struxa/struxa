<?php

declare(strict_types=1);

namespace App\Theme;

use App\Cache\FileCache;
use App\Plugin\PluginUpdateChecker;

/**
 * Compares installed theme versions with the distribution catalog or GitHub theme.json.
 *
 * @phpstan-type ThemeUpdateStatus array{
 *   update_available: bool,
 *   installed_version: string,
 *   latest_version: string|null,
 *   source: string|null,
 *   can_update: bool,
 *   catalog_has_package: bool,
 *   error: string|null
 * }
 */
final class ThemeUpdateChecker
{
    private const CACHE_KEY_CATALOG = 'theme_update_catalog_map_v1';

    private const CACHE_KEY_GITHUB_PREFIX = 'theme_update_github_v1_';

    private const CACHE_TTL = 3600;

    private const GITHUB_CACHE_TTL = 300;

    private const MAX_JSON_BYTES = 32_768;

    public function __construct(
        private readonly FileCache $cache,
        private readonly ThemeCatalogLoader $catalogLoader,
    ) {
    }

    /**
     * @return ThemeUpdateStatus
     */
    public function statusFor(ThemeManifest $theme, ?ThemeCatalogEntry $catalogEntry = null): array
    {
        $installed = $this->normalizeVersion($theme->version);
        $base = [
            'update_available' => false,
            'installed_version' => $installed,
            'latest_version' => null,
            'source' => null,
            'can_update' => false,
            'catalog_has_package' => false,
            'error' => null,
        ];

        $githubLatest = '';
        $githubError = null;
        $repoUrl = $theme->repositoryUrl;
        $github = ($repoUrl !== null && $repoUrl !== '')
            ? PluginUpdateChecker::parseGithubRepositoryUrl($repoUrl)
            : null;

        if ($github !== null) {
            $remote = $this->fetchGithubThemeVersion(
                $github['owner'],
                $github['repo'],
                PluginUpdateChecker::resolveGithubRef(),
                $theme->slug,
            );
            if ($remote === null) {
                $githubError = 'Could not read theme.json from GitHub (check repository_url points at the CMS monorepo).';
            } else {
                $githubLatest = $this->normalizeVersion($remote);
            }
        }

        $catalogLatest = '';
        $catalogCanUpdate = $catalogEntry !== null && trim($catalogEntry->downloadUrl) !== '';
        $base['catalog_has_package'] = $catalogCanUpdate;
        if ($catalogEntry !== null) {
            $catalogLatest = $this->normalizeVersion($catalogEntry->version);
        }

        $bestLatest = '';
        $bestSource = null;
        if ($githubLatest !== '' && $catalogLatest !== '') {
            if (version_compare($catalogLatest, $githubLatest, '>')) {
                $bestLatest = $catalogLatest;
                $bestSource = 'catalog';
            } else {
                $bestLatest = $githubLatest;
                $bestSource = 'github';
            }
        } elseif ($catalogLatest !== '') {
            $bestLatest = $catalogLatest;
            $bestSource = 'catalog';
        } elseif ($githubLatest !== '') {
            $bestLatest = $githubLatest;
            $bestSource = 'github';
        }

        if ($bestLatest === '') {
            $base['error'] = $githubError;

            return $base;
        }

        $base['latest_version'] = $bestLatest;
        $base['source'] = $bestSource;
        if (version_compare($bestLatest, $installed, '>')) {
            $base['update_available'] = true;
            if ($bestSource === 'catalog') {
                $base['can_update'] = $catalogCanUpdate;
            } elseif ($bestSource === 'github') {
                $base['can_update'] = $github !== null;
            }
        }

        if (!$base['update_available'] && $githubError !== null && $catalogLatest === '') {
            $base['error'] = $githubError;
        }

        return $base;
    }

    /**
     * @return array<string, ThemeCatalogEntry>
     */
    public function catalogEntriesBySlug(): array
    {
        $cached = $this->cache->get(self::CACHE_KEY_CATALOG);
        if (is_array($cached)) {
            $map = [];
            foreach ($cached as $slug => $row) {
                if (!is_string($slug) || !is_array($row)) {
                    continue;
                }
                $entry = self::catalogEntryFromArray($row);
                if ($entry !== null) {
                    $map[$slug] = $entry;
                }
            }
            if ($map !== []) {
                return $map;
            }
        }

        $loaded = $this->catalogLoader->load();
        if (!$loaded['ok']) {
            return [];
        }

        $map = [];
        $serializable = [];
        foreach ($loaded['entries'] as $entry) {
            $map[$entry->slug] = $entry;
            $serializable[$entry->slug] = self::catalogEntryToArray($entry);
        }
        $this->cache->set(self::CACHE_KEY_CATALOG, $serializable, self::CACHE_TTL);

        return $map;
    }

    /**
     * @return array<string, mixed>
     */
    private static function catalogEntryToArray(ThemeCatalogEntry $entry): array
    {
        return [
            'slug' => $entry->slug,
            'download_url' => $entry->downloadUrl,
            'name' => $entry->name,
            'version' => $entry->version,
            'description' => $entry->description,
            'author' => $entry->author,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function catalogEntryFromArray(array $row): ?ThemeCatalogEntry
    {
        $slug = isset($row['slug']) && is_string($row['slug']) ? strtolower(trim($row['slug'])) : '';
        $url = isset($row['download_url']) && is_string($row['download_url']) ? trim($row['download_url']) : '';
        if ($slug === '' || $url === '' || !ThemeManifest::isValidSlug($slug)) {
            return null;
        }

        return new ThemeCatalogEntry(
            $slug,
            $url,
            isset($row['name']) && is_string($row['name']) ? trim($row['name']) : $slug,
            isset($row['version']) && is_string($row['version']) ? trim($row['version']) : '',
            isset($row['description']) && is_string($row['description']) ? trim($row['description']) : '',
            isset($row['author']) && is_string($row['author']) ? trim($row['author']) : '',
        );
    }

    /**
     * Struxa Vision ships in the CMS monorepo (themes/{slug}/). The legacy struxa/struxa-theme repo is not updated.
     */
    private function fetchGithubThemeVersion(string $owner, string $repo, string $ref, string $themeSlug): ?string
    {
        $owner = trim($owner);
        $repo = trim($repo);
        $themeSlug = strtolower(trim($themeSlug));
        if ($owner === '' || $repo === '' || $themeSlug === '') {
            return null;
        }

        if (strtolower($owner) === 'struxa' && strtolower($repo) === 'struxa-theme') {
            $owner = 'struxa';
            $repo = 'struxa';
        }

        $paths = ['theme.json'];
        if (strtolower($owner) === 'struxa' && strtolower($repo) === 'struxa') {
            array_unshift($paths, 'themes/' . $themeSlug . '/theme.json');
        }

        foreach ($paths as $manifestPath) {
            $version = $this->fetchGithubThemeJsonAtPath($owner, $repo, $ref, $manifestPath);
            if ($version !== null && $version !== '') {
                return $version;
            }
        }

        return null;
    }

    private function fetchGithubThemeJsonAtPath(string $owner, string $repo, string $ref, string $manifestPath): ?string
    {
        $key = self::CACHE_KEY_GITHUB_PREFIX . hash('sha256', strtolower($owner . '/' . $repo . '@' . $ref . '@' . $manifestPath));
        $cached = $this->cache->get($key);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $url = sprintf(
            'https://raw.githubusercontent.com/%s/%s/%s/%s',
            rawurlencode($owner),
            rawurlencode($repo),
            rawurlencode($ref),
            ltrim($manifestPath, '/'),
        );
        $raw = $this->httpGetLimited($url);
        $version = $raw !== null && $raw !== '' ? $this->versionFromThemeJsonRaw($raw) : null;
        if ($version === null) {
            $version = $this->fetchGithubThemeVersionViaApi($owner, $repo, $ref, $manifestPath);
        }
        if ($version === null || $version === '') {
            return null;
        }

        $this->cache->set($key, $version, self::GITHUB_CACHE_TTL);

        return $version;
    }

    private function fetchGithubThemeVersionViaApi(string $owner, string $repo, string $ref, string $manifestPath): ?string
    {
        $url = sprintf(
            'https://api.github.com/repos/%s/%s/contents/%s?ref=%s',
            rawurlencode($owner),
            rawurlencode($repo),
            rawurlencode(ltrim($manifestPath, '/')),
            rawurlencode($ref),
        );
        $raw = $this->httpGetLimited($url, 'application/vnd.github+json');
        if ($raw === null || $raw === '') {
            return null;
        }

        try {
            /** @var mixed $data */
            $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($data)) {
            return null;
        }

        $encoding = isset($data['encoding']) && is_string($data['encoding']) ? strtolower($data['encoding']) : '';
        $content = isset($data['content']) && is_string($data['content']) ? $data['content'] : '';
        if ($encoding !== 'base64' || $content === '') {
            return null;
        }

        $content = str_replace(["\r", "\n", ' '], '', $content);
        $decoded = base64_decode($content, true);

        return $decoded !== false ? $this->versionFromThemeJsonRaw($decoded) : null;
    }

    private function versionFromThemeJsonRaw(string $raw): ?string
    {
        try {
            /** @var mixed $data */
            $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($data)) {
            return null;
        }

        $version = isset($data['version']) && is_string($data['version']) ? trim($data['version']) : '';

        return $version !== '' ? $version : null;
    }

    private function normalizeVersion(string $version): string
    {
        $version = trim($version);
        if ($version === '') {
            return '0.0.0';
        }
        if (preg_match('/^v(\d)/', $version) === 1) {
            $version = substr($version, 1);
        }

        return $version;
    }

    private function httpGetLimited(string $url, string $accept = 'application/json'): ?string
    {
        if (!str_starts_with($url, 'https://')) {
            return null;
        }

        $ua = 'Struxa-ThemeUpdateCheck/1.1 (+https://struxapoint.com)';

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch !== false) {
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS => 5,
                    CURLOPT_CONNECTTIMEOUT => 10,
                    CURLOPT_TIMEOUT => 20,
                    CURLOPT_HTTPHEADER => ['Accept: ' . $accept, 'User-Agent: ' . $ua],
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                ]);
                $raw = curl_exec($ch);
                $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                if (is_string($raw) && $code >= 200 && $code < 300 && strlen($raw) <= self::MAX_JSON_BYTES) {
                    return $raw;
                }
            }
        }

        $ctx = stream_context_create([
            'http' => [
                'timeout' => 15,
                'header' => "Accept: {$accept}\r\nUser-Agent: {$ua}\r\n",
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);
        $raw = @file_get_contents($url, false, $ctx);

        return is_string($raw) && strlen($raw) <= self::MAX_JSON_BYTES ? $raw : null;
    }
}
