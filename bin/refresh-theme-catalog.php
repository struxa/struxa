#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Rebuild struxa-theme.zip from themes/struxa-theme/ and update public/struxa-dist/repo.json
 * in one step (no Admin, no git). Preserves existing plugin rows in repo.json.
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

use App\Dist\StruxaDistCatalogWriter;
use App\Theme\ThemeManifest;

$envPath = $root . '/.env';
if (is_readable($envPath)) {
    Dotenv\Dotenv::createImmutable($root)->safeLoad();
}

$distRoot = is_dir($root . '/public/struxa-dist')
    ? $root . '/public/struxa-dist'
    : $root . '/struxa-dist';
$zipsDir = $distRoot . '/zips';
$themeDir = $root . '/themes/struxa-theme';
$manifestPath = $themeDir . '/theme.json';

if (!is_file($manifestPath)) {
    fwrite(STDERR, "ERROR: Missing {$manifestPath}\n");
    fwrite(STDERR, "Deploy CMS update or restore themes/struxa-theme/ first.\n");
    exit(1);
}

$manifest = ThemeManifest::tryLoadRelaxedPath($themeDir);
if ($manifest === null) {
    fwrite(STDERR, "ERROR: themes/struxa-theme is not a valid theme package.\n");
    exit(1);
}

if (!is_dir($zipsDir)) {
    mkdir($zipsDir, 0755, true);
}

$zipPath = $zipsDir . '/struxa-theme.zip';
echo "==> Bundled theme version on disk:\n";
echo '  "' . $manifest->version . "\"\n";

$republish = $root . '/scripts/republish-bundled-theme.sh';
if (is_file($republish)) {
    passthru('bash ' . escapeshellarg($republish), $code);
    if ($code !== 0) {
        exit($code);
    }
} else {
    if (!class_exists(ZipArchive::class)) {
        fwrite(STDERR, "ERROR: PHP zip extension required.\n");
        exit(1);
    }
    if (is_file($zipPath)) {
        unlink($zipPath);
    }
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        fwrite(STDERR, "ERROR: Could not create {$zipPath}\n");
        exit(1);
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($themeDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    $prefix = realpath($themeDir);
    if ($prefix === false) {
        fwrite(STDERR, "ERROR: Could not resolve theme directory.\n");
        exit(1);
    }
    $prefix .= DIRECTORY_SEPARATOR;
    foreach ($it as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile()) {
            continue;
        }
        $path = $file->getPathname();
        if (!str_starts_with($path, $prefix)) {
            continue;
        }
        $rel = substr($path, strlen($prefix));
        $zip->addFile($path, str_replace('\\', '/', $rel));
    }
    $zip->close();
    echo "==> Built {$zipPath}\n";
}

try {
    /** @var mixed $raw */
    $raw = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    fwrite(STDERR, 'ERROR: Invalid theme.json: ' . $e->getMessage() . "\n");
    exit(1);
}
if (!is_array($raw)) {
    fwrite(STDERR, "ERROR: theme.json must be a JSON object.\n");
    exit(1);
}

$siteBase = catalogSiteBaseUrl($root);
$slug = $manifest->slug;
$entry = [
    'slug' => $slug,
    'name' => trim((string) ($raw['name'] ?? $slug)),
    'version' => trim((string) ($raw['version'] ?? '1.0.0')),
    'description' => trim((string) ($raw['description'] ?? '')),
    'author' => trim((string) ($raw['author'] ?? '')),
    'download_url' => $siteBase . '/struxa-catalog/download/theme/' . rawurlencode($slug),
];
$repo = isset($raw['repository_url']) && is_string($raw['repository_url']) ? trim($raw['repository_url']) : '';
if ($repo !== '') {
    if (preg_match('#github\.com/struxa/struxa-theme#i', $repo) === 1 && $slug === 'struxa-theme') {
        $repo = 'https://github.com/struxa/struxa';
    }
    $entry['repository_url'] = $repo;
}
$min = trim((string) ($raw['min_cms_version'] ?? ''));
if ($min !== '') {
    $entry['requires_cms_version'] = $min;
}

$themes = [];
$plugins = [];
$repoPath = $distRoot . '/repo.json';
if (is_readable($repoPath)) {
    try {
        /** @var mixed $catalog */
        $catalog = json_decode((string) file_get_contents($repoPath), true, 512, JSON_THROW_ON_ERROR);
        if (is_array($catalog)) {
            if (isset($catalog['themes']) && is_array($catalog['themes'])) {
                foreach ($catalog['themes'] as $row) {
                    if (is_array($row) && ($row['slug'] ?? '') !== $slug) {
                        $themes[] = $row;
                    }
                }
            }
            if (isset($catalog['plugins']) && is_array($catalog['plugins'])) {
                if (isset($catalog['plugins']['shards']) && is_array($catalog['plugins']['shards'])) {
                    fwrite(STDERR, "WARN: Sharded plugin catalog not merged; plugin index unchanged.\n");
                } else {
                    foreach ($catalog['plugins'] as $row) {
                        if (is_array($row)) {
                            $plugins[] = $row;
                        }
                    }
                }
            }
        }
    } catch (JsonException) {
        fwrite(STDERR, "WARN: Could not parse existing repo.json; writing fresh theme row only.\n");
    }
}

$themes[] = $entry;
usort($themes, static fn (array $a, array $b): int => strcmp((string) ($a['slug'] ?? ''), (string) ($b['slug'] ?? '')));
usort($plugins, static fn (array $a, array $b): int => strcmp((string) ($a['slug'] ?? ''), (string) ($b['slug'] ?? '')));

$written = (new StruxaDistCatalogWriter())->write($distRoot, $themes, $plugins);
if (!$written['ok']) {
    fwrite(STDERR, ($written['error'] ?? 'Catalog write failed.') . "\n");
    exit(1);
}

echo "==> Wrote {$distRoot}/repo.json (struxa-theme v{$entry['version']})\n";
echo "    Verify: {$siteBase}/struxa-dist/repo.json\n";
echo "    Admin → Themes → Reinstall from catalog (v{$entry['version']})\n";

function catalogSiteBaseUrl(string $root): string
{
    $fromEnv = trim((string) ($_ENV['PHPAUTH_SITE_URL'] ?? getenv('PHPAUTH_SITE_URL') ?: ''));
    if ($fromEnv !== '') {
        return rtrim($fromEnv, '/');
    }

    if (is_readable($root . '/.env')) {
        try {
            $dbHost = App\Cli\CmsCliEnv::get('DB_HOST', '127.0.0.1');
            $dbPort = App\Cli\CmsCliEnv::get('DB_PORT', '3306');
            $dbName = App\Cli\CmsCliEnv::get('DB_NAME', 'studio');
            $dbUser = App\Cli\CmsCliEnv::get('DB_USER', 'studio');
            $dbPass = App\Cli\CmsCliEnv::get('DB_PASS', 'studio');
            $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $dbHost, $dbPort, $dbName);
            $pdo = new PDO($dsn, $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $row = $pdo->query("SELECT value FROM settings WHERE `key` = 'site_url' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            if (is_array($row) && is_string($row['value'] ?? null)) {
                $v = trim($row['value']);
                if ($v !== '') {
                    return rtrim($v, '/');
                }
            }
        } catch (Throwable) {
            // fall through
        }
    }

    return 'https://struxapoint.com';
}
