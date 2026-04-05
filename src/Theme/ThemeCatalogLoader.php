<?php

declare(strict_types=1);

namespace App\Theme;

/**
 * Loads {@see ThemeCatalogEntry} records from a JSON file or HTTPS URL.
 */
final class ThemeCatalogLoader
{
    private const MAX_CATALOG_BYTES = 512_000;

    /** Default public theme registry when STRUXA_THEME_CATALOG_URL is unset (must return JSON with a "themes" array). */
    private const DEFAULT_CATALOG_URL = 'https://struxapoint.com/struxa-dist/repo.json';

    public function __construct(
        private readonly string $projectRoot,
    ) {
    }

    /**
     * @return array{ok: true, entries: list<ThemeCatalogEntry>}|array{ok: false, error: string}
     */
    public function load(): array
    {
        $raw = trim((string) ($_ENV['STRUXA_THEME_CATALOG_URL'] ?? getenv('STRUXA_THEME_CATALOG_URL') ?? ''));
        if ($raw !== '' && !str_starts_with($raw, 'https://')) {
            return ['ok' => false, 'error' => 'STRUXA_THEME_CATALOG_URL must be an https:// URL (or leave unset to use the default catalog + local fallback).'];
        }

        $json = null;
        if ($raw !== '') {
            $json = $this->httpGetLimited($raw, self::MAX_CATALOG_BYTES);
            if ($json === null) {
                return ['ok' => false, 'error' => 'Could not download theme catalog from STRUXA_THEME_CATALOG_URL.'];
            }
        } else {
            $json = $this->httpGetLimited(self::DEFAULT_CATALOG_URL, self::MAX_CATALOG_BYTES);
            if ($json === null || $json === '') {
                $path = $this->projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'theme-catalog.json';
                if (is_readable($path)) {
                    $loaded = file_get_contents($path);
                    $json = $loaded !== false ? $loaded : null;
                }
            }
            if ($json === false || $json === null || $json === '') {
                return [
                    'ok' => false,
                    'error' => 'Could not load the theme catalog from ' . self::DEFAULT_CATALOG_URL . ' and storage/theme-catalog.json is missing or unreadable. Set STRUXA_THEME_CATALOG_URL or add a local catalog file.',
                ];
            }
        }

        try {
            /** @var mixed $data */
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['ok' => false, 'error' => 'Theme catalog is not valid JSON.'];
        }
        if (!is_array($data) || !isset($data['themes']) || !is_array($data['themes'])) {
            return ['ok' => false, 'error' => 'Theme catalog must be an object with a "themes" array.'];
        }

        $entries = [];
        foreach ($data['themes'] as $i => $row) {
            if (!is_array($row)) {
                return ['ok' => false, 'error' => 'Catalog themes[' . $i . '] must be an object.'];
            }
            $slug = strtolower(trim((string) ($row['slug'] ?? '')));
            $url = trim((string) ($row['download_url'] ?? ''));
            if (!ThemeManifest::isValidSlug($slug)) {
                return ['ok' => false, 'error' => 'Catalog themes[' . $i . '].slug is invalid.'];
            }
            if (!str_starts_with($url, 'https://') || !ThemeRemoteInstaller::isDownloadUrlHostAllowed($url)) {
                return [
                    'ok' => false,
                    'error' => 'Catalog themes[' . $i . '].download_url must be https and use an allowed host (see STRUXA_THEME_DOWNLOAD_HOSTS).',
                ];
            }
            $name = trim((string) ($row['name'] ?? $slug));
            $version = trim((string) ($row['version'] ?? ''));
            $description = trim((string) ($row['description'] ?? ''));
            $author = trim((string) ($row['author'] ?? ''));
            if (strlen($name) > 160) {
                $name = substr($name, 0, 160);
            }
            if (strlen($description) > 2000) {
                $description = substr($description, 0, 2000);
            }
            $entries[] = new ThemeCatalogEntry($slug, $url, $name, $version, $description, $author);
        }

        return ['ok' => true, 'entries' => $entries];
    }

    /**
     * @return list<ThemeCatalogEntry>
     */
    public function loadOrEmpty(): array
    {
        $r = $this->load();

        return $r['ok'] ? $r['entries'] : [];
    }

    private function httpGetLimited(string $url, int $maxBytes): ?string
    {
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 20,
                'follow_location' => 1,
                'max_redirects' => 5,
                'header' => "User-Agent: Struxa-ThemeCatalog/1.0\r\nAccept: application/json\r\n",
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
