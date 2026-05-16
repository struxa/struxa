<?php

declare(strict_types=1);

namespace App\Theme;

use App\Dist\StruxaDistCatalogClient;

/**
 * Loads {@see ThemeCatalogEntry} records from the Struxa distribution catalog.
 */
final class ThemeCatalogLoader
{
    public function __construct(
        private readonly string $projectRoot,
    ) {
    }

    /**
     * @return array{ok: true, entries: list<ThemeCatalogEntry>}|array{ok: false, error: string}
     */
    public function load(): array
    {
        $loaded = (new StruxaDistCatalogClient($this->projectRoot))->loadCatalog();
        if (!$loaded['ok']) {
            return ['ok' => false, 'error' => $loaded['error']];
        }
        $data = $loaded['data'];
        if (!isset($data['themes']) || !is_array($data['themes'])) {
            return ['ok' => false, 'error' => 'Distribution catalog must include a "themes" array.'];
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
}
