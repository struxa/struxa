<?php

declare(strict_types=1);

/**
 * Build struxa-dist/repo.json from theme.json and plugin.json manifests.
 * Run via scripts/build-struxa-dist.sh (also builds ZIPs).
 *
 * Allowlist: struxa-dist/publish.json (default = default theme only, no plugins).
 */

$root = dirname(__DIR__);
$distRoot = $root . '/struxa-dist';
$zipsDir = $distRoot . '/zips';
$downloadBase = 'https://struxapoint.com/struxa-catalog/download';
$publishPath = $distRoot . '/publish.json';

/** @var list<string> $publishThemes */
$publishThemes = ['default'];
/** @var list<string> $publishPluginSlugs */
$publishPluginSlugs = [];
$includePlugins = false;
if (is_readable($publishPath)) {
    try {
        /** @var mixed $publish */
        $publish = json_decode((string) file_get_contents($publishPath), true, 512, JSON_THROW_ON_ERROR);
        if (is_array($publish)) {
            if (isset($publish['themes']) && is_array($publish['themes'])) {
                $publishThemes = [];
                foreach ($publish['themes'] as $t) {
                    if (is_string($t) && $t !== '') {
                        $publishThemes[] = strtolower(trim($t));
                    }
                }
            }
            if (isset($publish['plugins']) && is_array($publish['plugins'])) {
                foreach ($publish['plugins'] as $p) {
                    if (is_string($p) && $p !== '') {
                        $publishPluginSlugs[] = strtolower(trim($p));
                    }
                }
            }
            $includePlugins = !empty($publish['include_plugins']);
        }
    } catch (JsonException $e) {
        fwrite(STDERR, "Invalid publish.json: {$e->getMessage()}\n");
        exit(1);
    }
}
$publishPluginSet = array_fill_keys($publishPluginSlugs, true);
if ($publishThemes === []) {
    $publishThemes = ['default'];
}
$publishThemeSet = array_fill_keys($publishThemes, true);

if (!is_dir($zipsDir)) {
    mkdir($zipsDir, 0755, true);
}

$themes = [];
$themesDir = $root . '/themes';
if (is_dir($themesDir)) {
    foreach (scandir($themesDir) ?: [] as $dir) {
        if ($dir === '.' || $dir === '..' || !is_dir($themesDir . '/' . $dir)) {
            continue;
        }
        $manifestPath = $themesDir . '/' . $dir . '/theme.json';
        if (!is_file($manifestPath)) {
            continue;
        }
        try {
            $data = json_decode((string) file_get_contents($manifestPath), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            fwrite(STDERR, "Skip theme {$dir}: invalid theme.json\n");
            continue;
        }
        if (!is_array($data)) {
            continue;
        }
        $slug = strtolower(trim((string) ($data['slug'] ?? $dir)));
        if (!isset($publishThemeSet[$slug])) {
            continue;
        }
        $zipPath = $zipsDir . '/' . $slug . '.zip';
        if (!is_file($zipPath)) {
            fwrite(STDERR, "Skip theme {$dir}: missing {$zipPath}\n");
            continue;
        }
        $themes[] = [
            'slug' => $slug,
            'name' => trim((string) ($data['name'] ?? $slug)),
            'version' => trim((string) ($data['version'] ?? '1.0.0')),
            'description' => trim((string) ($data['description'] ?? '')),
            'author' => trim((string) ($data['author'] ?? '')),
            'download_url' => $downloadBase . '/theme/' . rawurlencode($slug),
        ];
    }
}

usort($themes, static fn (array $a, array $b): int => strcmp($a['slug'], $b['slug']));

$plugins = [];
$pluginsDir = $root . '/plugins';
$addPluginFromManifest = static function (string $slug, array $data) use ($downloadBase, &$plugins): void {
    $slug = strtolower(trim($slug));
    $entry = [
        'slug' => $slug,
        'name' => trim((string) ($data['name'] ?? $slug)),
        'version' => trim((string) ($data['version'] ?? '1.0.0')),
        'description' => trim((string) ($data['description'] ?? '')),
        'author' => trim((string) ($data['author'] ?? '')),
        'download_url' => $downloadBase . '/plugin/' . rawurlencode($slug),
    ];
    $req = isset($data['requires_cms_version']) && is_string($data['requires_cms_version']) && trim($data['requires_cms_version']) !== ''
        ? trim($data['requires_cms_version'])
        : (isset($data['min_cms_version']) && is_string($data['min_cms_version']) ? trim($data['min_cms_version']) : null);
    if ($req !== null && $req !== '') {
        $entry['requires_cms_version'] = $req;
    }
    if ($slug === 'stripe-store-plugin') {
        $entry['description'] = trim($entry['description'] . ' After install: run composer plugin-deps at the CMS root (Stripe PHP SDK).');
    }
    $plugins[] = $entry;
};

if ($publishPluginSlugs !== []) {
    foreach ($publishPluginSlugs as $slug) {
        $zipPath = $zipsDir . '/' . $slug . '.zip';
        if (!is_file($zipPath)) {
            fwrite(STDERR, "Skip plugin {$slug}: missing {$zipPath}\n");
            continue;
        }
        $manifestPath = is_dir($pluginsDir . '/' . $slug) ? $pluginsDir . '/' . $slug . '/plugin.json' : null;
        if ($manifestPath !== null && is_file($manifestPath)) {
            try {
                $data = json_decode((string) file_get_contents($manifestPath), true, 32, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                fwrite(STDERR, "Skip plugin {$slug}: invalid plugin.json\n");
                continue;
            }
            if (is_array($data)) {
                $addPluginFromManifest($slug, $data);
            }
        }
    }
} elseif ($includePlugins && is_dir($pluginsDir)) {
    foreach (scandir($pluginsDir) ?: [] as $dir) {
        if ($dir === '.' || $dir === '..' || !is_dir($pluginsDir . '/' . $dir)) {
            continue;
        }
        $manifestPath = $pluginsDir . '/' . $dir . '/plugin.json';
        if (!is_file($manifestPath)) {
            continue;
        }
        $zipPath = $zipsDir . '/' . $dir . '.zip';
        if (!is_file($zipPath)) {
            fwrite(STDERR, "Skip plugin {$dir}: missing {$zipPath}\n");
            continue;
        }
        try {
            $data = json_decode((string) file_get_contents($manifestPath), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            fwrite(STDERR, "Skip plugin {$dir}: invalid plugin.json\n");
            continue;
        }
        if (!is_array($data)) {
            continue;
        }
        $addPluginFromManifest((string) ($data['slug'] ?? $dir), $data);
    }
}
usort($plugins, static fn (array $a, array $b): int => strcmp($a['slug'], $b['slug']));

$catalog = [
    'catalog_version' => 1,
    'generated_at' => gmdate('c'),
    'themes' => $themes,
    'plugins' => $plugins,
];

$out = json_encode($catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if ($out === false) {
    fwrite(STDERR, "Failed to encode catalog JSON.\n");
    exit(1);
}

file_put_contents($distRoot . '/repo.json', $out . "\n");
echo 'Wrote ' . $distRoot . '/repo.json (' . count($themes) . ' themes, ' . count($plugins) . " plugins)\n";
