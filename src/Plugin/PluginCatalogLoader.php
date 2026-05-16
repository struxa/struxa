<?php

declare(strict_types=1);

namespace App\Plugin;

use App\Dist\StruxaDistCatalogClient;
use App\Theme\ThemeRemoteInstaller;

/**
 * Loads {@see PluginCatalogEntry} records from the Struxa distribution catalog.
 */
final class PluginCatalogLoader
{
    public function __construct(
        private readonly string $projectRoot,
    ) {
    }

    /**
     * @return array{ok: true, entries: list<PluginCatalogEntry>}|array{ok: false, error: string}
     */
    public function load(): array
    {
        $loaded = (new StruxaDistCatalogClient($this->projectRoot))->loadCatalog();
        if (!$loaded['ok']) {
            return ['ok' => false, 'error' => $loaded['error']];
        }
        $data = $loaded['data'];
        if (!isset($data['plugins']) || !is_array($data['plugins'])) {
            return ['ok' => false, 'error' => 'Distribution catalog must include a "plugins" array.'];
        }

        $entries = [];
        foreach ($data['plugins'] as $i => $row) {
            if (!is_array($row)) {
                return ['ok' => false, 'error' => 'Catalog plugins[' . $i . '] must be an object.'];
            }
            $slug = strtolower(trim((string) ($row['slug'] ?? '')));
            $url = trim((string) ($row['download_url'] ?? ''));
            if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
                return ['ok' => false, 'error' => 'Catalog plugins[' . $i . '].slug is invalid.'];
            }
            if (!str_starts_with($url, 'https://') || !ThemeRemoteInstaller::isDownloadUrlHostAllowed($url)) {
                return [
                    'ok' => false,
                    'error' => 'Catalog plugins[' . $i . '].download_url must be https and use an allowed host (STRUXA_THEME_DOWNLOAD_HOSTS).',
                ];
            }
            $name = trim((string) ($row['name'] ?? $slug));
            $version = trim((string) ($row['version'] ?? ''));
            $description = trim((string) ($row['description'] ?? ''));
            $author = trim((string) ($row['author'] ?? ''));
            $reqCms = isset($row['requires_cms_version']) && is_string($row['requires_cms_version']) && trim($row['requires_cms_version']) !== ''
                ? trim($row['requires_cms_version'])
                : null;
            if (strlen($name) > 160) {
                $name = substr($name, 0, 160);
            }
            if (strlen($description) > 2000) {
                $description = substr($description, 0, 2000);
            }
            $entries[] = new PluginCatalogEntry($slug, $url, $name, $version, $description, $author, $reqCms);
        }

        return ['ok' => true, 'entries' => $entries];
    }
}
