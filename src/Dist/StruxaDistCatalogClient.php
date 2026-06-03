<?php

declare(strict_types=1);

namespace App\Dist;

/**
 * Fetches the public Struxa distribution catalog (themes + plugins) from HTTPS or local storage.
 */
final class StruxaDistCatalogClient
{
    public const DEFAULT_CATALOG_URL = 'https://struxapoint.com/struxa-dist/repo.json';

    private const MAX_BYTES = 512_000;

    private const MAX_SHARD_BYTES = 512_000;

    public function __construct(
        private readonly string $projectRoot,
    ) {
    }

    public function resolveCatalogUrl(): string
    {
        foreach (['STRUXA_DIST_CATALOG_URL', 'STRUXA_THEME_CATALOG_URL', 'STRUXA_PLUGIN_CATALOG_URL'] as $key) {
            $v = trim((string) ($_ENV[$key] ?? getenv($key) ?: ''));
            if ($v !== '') {
                return $v;
            }
        }

        return self::DEFAULT_CATALOG_URL;
    }

    /**
     * @return array{ok: true, data: array<string, mixed>}|array{ok: false, error: string}
     */
    public function loadCatalog(): array
    {
        $url = $this->resolveCatalogUrl();
        if ($url !== '' && !str_starts_with($url, 'https://')) {
            return ['ok' => false, 'error' => 'Catalog URL must use https:// (STRUXA_DIST_CATALOG_URL or STRUXA_THEME_CATALOG_URL).'];
        }

        $json = null;
        if ($url !== '') {
            $json = $this->httpGetLimited($url, self::MAX_BYTES);
        }
        if ($json === null || $json === '') {
            $json = $this->readPublishedCatalogFromDisk('repo.json');
        }
        if ($json === null || $json === '') {
            $json = $this->readLocalFallback();
        }
        if ($json === null || $json === '') {
            return [
                'ok' => false,
                'error' => 'Could not load the catalog from ' . ($url !== '' ? $url : self::DEFAULT_CATALOG_URL)
                    . '. Host repo.json on HTTPS or add storage/dist-catalog.json (see storage/dist-catalog.example.json).',
            ];
        }

        try {
            /** @var mixed $data */
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['ok' => false, 'error' => 'Distribution catalog is not valid JSON.'];
        }
        if (!is_array($data)) {
            return ['ok' => false, 'error' => 'Distribution catalog must be a JSON object.'];
        }

        $normalized = $this->normalizeCatalogData($data, $this->catalogBaseFromUrl($url));

        return ['ok' => true, 'data' => $normalized];
    }

    /**
     * Theme and plugin counts for the storefront GitHub showcase.
     * Reads repo-summary.json when available so large catalogs do not require parsing every plugin entry.
     *
     * @return array{themes: int, plugins: int, generated_at: ?string}
     */
    public function loadShowcaseCounts(): array
    {
        $fromIndex = $this->countsFromCatalogIndex();
        $summary = $this->readSummary();

        if ($fromIndex !== null && $summary !== null) {
            $summaryThemes = max(0, (int) ($summary['themes_count'] ?? 0));
            $summaryPlugins = max(0, (int) ($summary['plugins_count'] ?? 0));
            $countsDiffer = $fromIndex['themes'] !== $summaryThemes || $fromIndex['plugins'] !== $summaryPlugins;
            $summaryAt = $this->parseGeneratedTimestamp(
                isset($summary['generated_at']) && is_string($summary['generated_at']) ? $summary['generated_at'] : null
            );
            $indexAt = $this->parseGeneratedTimestamp($fromIndex['generated_at'] ?? null);
            $indexNewer = $indexAt !== null && ($summaryAt === null || $indexAt > $summaryAt);

            if (!$countsDiffer && !$indexNewer) {
                return [
                    'themes' => $summaryThemes,
                    'plugins' => $summaryPlugins,
                    'generated_at' => isset($summary['generated_at']) && is_string($summary['generated_at'])
                        ? $summary['generated_at']
                        : $fromIndex['generated_at'],
                ];
            }

            return $fromIndex;
        }

        if ($fromIndex !== null) {
            return $fromIndex;
        }

        if ($summary !== null) {
            return [
                'themes' => max(0, (int) ($summary['themes_count'] ?? 0)),
                'plugins' => max(0, (int) ($summary['plugins_count'] ?? 0)),
                'generated_at' => isset($summary['generated_at']) && is_string($summary['generated_at'])
                    ? $summary['generated_at']
                    : null,
            ];
        }

        $data = $this->loadShowcaseCatalogData();

        return [
            'themes' => is_array($data['themes'] ?? null) ? count($data['themes']) : 0,
            'plugins' => is_array($data['plugins'] ?? null) ? count($data['plugins']) : 0,
            'generated_at' => isset($data['generated_at']) && is_string($data['generated_at'])
                ? $data['generated_at']
                : null,
        ];
    }

    /**
     * Cheap counts from repo.json index (v1 arrays or v2 totals) without loading plugin shards.
     *
     * @return array{themes: int, plugins: int, generated_at: ?string}|null
     */
    private function countsFromCatalogIndex(): ?array
    {
        $json = $this->readCatalogIndexJson();
        if ($json === null) {
            return null;
        }
        try {
            /** @var mixed $data */
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($data)) {
            return null;
        }

        $generatedAt = isset($data['generated_at']) && is_string($data['generated_at'])
            ? $data['generated_at']
            : null;
        $catalogVersion = (int) ($data['catalog_version'] ?? 1);

        if ($catalogVersion >= 2 && isset($data['totals']) && is_array($data['totals'])) {
            return [
                'themes' => max(0, (int) ($data['totals']['themes'] ?? 0)),
                'plugins' => max(0, (int) ($data['totals']['plugins'] ?? 0)),
                'generated_at' => $generatedAt,
            ];
        }

        $themes = is_array($data['themes'] ?? null) ? count($data['themes']) : 0;
        $pluginsRaw = $data['plugins'] ?? null;
        if (is_array($pluginsRaw) && !$this->isList($pluginsRaw)) {
            return null;
        }
        $plugins = is_array($pluginsRaw) ? count($pluginsRaw) : 0;

        return [
            'themes' => $themes,
            'plugins' => $plugins,
            'generated_at' => $generatedAt,
        ];
    }

    private function readCatalogIndexJson(): ?string
    {
        $json = $this->readPublishedCatalogFromDisk('repo.json');
        if ($json === null || $json === '') {
            $url = $this->resolveCatalogUrl();
            if ($url !== '' && str_starts_with($url, 'https://')) {
                $json = $this->httpGetLimited($url, 65_536);
            }
        }

        return ($json !== null && $json !== '') ? $json : null;
    }

    private function parseGeneratedTimestamp(?string $raw): ?int
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }
        $ts = strtotime(trim($raw));

        return $ts !== false ? $ts : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function readSummary(): ?array
    {
        $json = $this->readPublishedCatalogFromDisk('repo-summary.json');
        if ($json === null || $json === '') {
            $url = trim((string) ($_ENV['STRUXA_DIST_CATALOG_URL'] ?? getenv('STRUXA_DIST_CATALOG_URL') ?: ''));
            if ($url === '') {
                $url = self::DEFAULT_CATALOG_URL;
            }
            if (str_starts_with($url, 'https://') && str_ends_with($url, 'repo.json')) {
                $summaryUrl = substr($url, 0, -strlen('repo.json')) . 'repo-summary.json';
                $json = $this->httpGetLimited($summaryUrl, 16_384);
            }
        }
        if ($json === null || $json === '') {
            return null;
        }
        try {
            /** @var mixed $data */
            $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($data) ? $data : null;
    }

    /**
     * Expand v2 sharded catalogs into the v1 shape callers expect.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function normalizeCatalogData(array $data, string $catalogBaseUrl = ''): array
    {
        $plugins = $data['plugins'] ?? null;
        if (!is_array($plugins) || $this->isList($plugins)) {
            return $data;
        }

        $shards = $plugins['shards'] ?? null;
        if (!is_array($shards) || $shards === []) {
            $data['plugins'] = [];

            return $data;
        }

        $merged = [];
        foreach ($shards as $shard) {
            if (!is_string($shard) || trim($shard) === '') {
                continue;
            }
            foreach ($this->loadPluginShard($shard, $catalogBaseUrl) as $entry) {
                $merged[] = $entry;
            }
        }
        $data['plugins'] = $merged;

        return $data;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadPluginShard(string $shardRef, string $catalogBaseUrl): array
    {
        $json = null;
        if (str_starts_with($shardRef, 'https://')) {
            $json = $this->httpGetLimited($shardRef, self::MAX_SHARD_BYTES);
        } else {
            $relative = ltrim(str_replace('\\', '/', $shardRef), '/');
            $json = $this->readPublishedCatalogFromDisk($relative);
            if (($json === null || $json === '') && $catalogBaseUrl !== '') {
                $json = $this->httpGetLimited(rtrim($catalogBaseUrl, '/') . '/' . $relative, self::MAX_SHARD_BYTES);
            }
        }
        if ($json === null || $json === '') {
            return [];
        }
        try {
            /** @var mixed $data */
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        if (!is_array($data) || !isset($data['plugins']) || !is_array($data['plugins'])) {
            return [];
        }
        $out = [];
        foreach ($data['plugins'] as $row) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadShowcaseCatalogData(): array
    {
        $json = $this->readPublishedCatalogFromDisk('repo.json');
        if ($json === null || $json === '') {
            $url = trim((string) ($_ENV['STRUXA_DIST_CATALOG_URL'] ?? getenv('STRUXA_DIST_CATALOG_URL') ?: ''));
            if ($url === '') {
                $url = self::DEFAULT_CATALOG_URL;
            }
            if ($url !== '' && str_starts_with($url, 'https://')) {
                $json = $this->httpGetLimited($url, self::MAX_BYTES);
            }
        }
        if ($json === null || $json === '') {
            $json = $this->readLocalFallback();
        }
        if ($json === null || $json === '') {
            $loaded = $this->loadCatalog();
            if (!$loaded['ok']) {
                return [];
            }

            return $loaded['data'];
        }

        try {
            /** @var mixed $data */
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        if (!is_array($data)) {
            return [];
        }

        return $this->normalizeCatalogData($data, $this->catalogBaseFromUrl($this->resolveCatalogUrl()));
    }

    private function catalogBaseFromUrl(string $url): string
    {
        if ($url === '' || !str_starts_with($url, 'https://')) {
            return 'https://struxapoint.com/struxa-dist';
        }
        if (str_ends_with($url, 'repo.json')) {
            return substr($url, 0, -strlen('repo.json'));
        }

        return rtrim($url, '/');
    }

    /**
     * When the CMS and catalog share a host, HTTP self-fetch often fails; read the published file instead.
     */
    private function readPublishedCatalogFromDisk(string $relativePath): ?string
    {
        $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
        foreach ([
            $this->projectRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'struxa-dist' . DIRECTORY_SEPARATOR . $relativePath,
            $this->projectRoot . DIRECTORY_SEPARATOR . 'struxa-dist' . DIRECTORY_SEPARATOR . $relativePath,
        ] as $path) {
            if (!is_readable($path)) {
                continue;
            }
            $loaded = file_get_contents($path);
            if ($loaded !== false && $loaded !== '') {
                return $loaded;
            }
        }

        return null;
    }

    private function readLocalFallback(): ?string
    {
        foreach (['dist-catalog.json', 'theme-catalog.json'] as $name) {
            $path = $this->projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . $name;
            if (!is_readable($path)) {
                continue;
            }
            $loaded = file_get_contents($path);
            if ($loaded === false || $loaded === '') {
                continue;
            }
            if ($name === 'theme-catalog.json') {
                try {
                    $data = json_decode($loaded, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    continue;
                }
                if (is_array($data) && isset($data['themes']) && !isset($data['plugins'])) {
                    $data['plugins'] = [];

                    return json_encode($data, JSON_THROW_ON_ERROR);
                }
            }

            return $loaded;
        }

        return null;
    }

    private function httpGetLimited(string $url, int $maxBytes): ?string
    {
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 20,
                'follow_location' => 1,
                'max_redirects' => 5,
                'header' => "User-Agent: Struxa-DistCatalog/1.0\r\nAccept: application/json\r\n",
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
        if (strlen($data) >= $maxBytes || $data === '') {
            return null;
        }

        return $data;
    }

    /**
     * @param array<mixed> $value
     */
    private function isList(array $value): bool
    {
        if ($value === []) {
            return true;
        }

        return array_keys($value) === range(0, count($value) - 1);
    }
}
