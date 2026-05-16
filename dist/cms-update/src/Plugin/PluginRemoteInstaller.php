<?php

declare(strict_types=1);

namespace App\Plugin;

use App\Filesystem\SafeDirectoryRemoval;
use App\Theme\ThemeRemoteInstaller;
use ZipArchive;

/**
 * Downloads a plugin ZIP from the catalog and installs under plugins/{slug}/.
 */
final class PluginRemoteInstaller
{
    private const MAX_ZIP_BYTES = 45_000_000;

    public function __construct(
        private readonly string $pluginsRoot,
        private readonly PluginScanner $scanner,
    ) {
    }

    /**
     * @param list<PluginCatalogEntry> $catalogEntries
     */
    public function installFromCatalogSlug(string $slug, array $catalogEntries): ?string
    {
        $slug = strtolower(trim($slug));
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            return 'Invalid plugin slug.';
        }
        $entry = null;
        foreach ($catalogEntries as $e) {
            if ($e->slug === $slug) {
                $entry = $e;
                break;
            }
        }
        if ($entry === null) {
            return 'That plugin is not in the current catalog.';
        }
        if ($entry->requiresCmsVersion !== null && !version_compare(\App\CmsVersion::CURRENT, $entry->requiresCmsVersion, '>=')) {
            return 'This package requires CMS ' . $entry->requiresCmsVersion . ' or newer.';
        }
        if (!ThemeRemoteInstaller::isDownloadUrlHostAllowed($entry->downloadUrl)) {
            return 'Download URL is not allowed.';
        }
        if (!class_exists(ZipArchive::class)) {
            return 'PHP zip extension (ZipArchive) is required to install plugins from the catalog.';
        }
        if ($this->scanner->findBySlug($slug) !== null) {
            return 'That plugin is already installed. Remove it first if you want to replace it.';
        }

        $zipBody = $this->httpGetLimited($entry->downloadUrl, self::MAX_ZIP_BYTES);
        if ($zipBody === null) {
            return 'Could not download the plugin package (size limit, network, or invalid response).';
        }

        $work = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'struxa-plugin-' . bin2hex(random_bytes(8));
        if (!@mkdir($work, 0700, true) || !is_dir($work)) {
            return 'Could not create a temporary working directory.';
        }
        $zipPath = $work . DIRECTORY_SEPARATOR . 'package.zip';
        $extractDir = $work . DIRECTORY_SEPARATOR . 'extract';
        try {
            if (file_put_contents($zipPath, $zipBody) === false) {
                return 'Could not save the downloaded package.';
            }
            if (!@mkdir($extractDir, 0700, true)) {
                return 'Could not create extract directory.';
            }
            $zip = new ZipArchive();
            if ($zip->open($zipPath) !== true) {
                return 'Downloaded file is not a valid ZIP archive.';
            }
            if (!$this->extractZipSafely($zip, $extractDir)) {
                $zip->close();

                return 'Plugin archive contains unsafe paths and was rejected.';
            }
            $zip->close();

            $pluginRoot = $this->locatePluginRoot($extractDir, $slug);
            if ($pluginRoot === null) {
                return 'No valid plugin (plugin.json at package root or in a single folder) was found in the archive.';
            }

            $parser = new PluginManifestParser();
            $parsed = $parser->parseFile($pluginRoot . DIRECTORY_SEPARATOR . 'plugin.json', $slug);
            if (!$parsed['ok']) {
                return 'Plugin package failed validation: ' . $parsed['error'];
            }
            if ($parsed['manifest']->slug !== $slug) {
                return 'Archive plugin slug "' . $parsed['manifest']->slug . '" does not match catalog slug "' . $slug . '".';
            }

            $dest = $this->pluginsRoot . DIRECTORY_SEPARATOR . $slug;
            if (is_dir($dest)) {
                return 'Target plugin directory already exists.';
            }
            $err = $this->copyDirectoryTree($pluginRoot, $dest);
            if ($err !== null) {
                return $err;
            }

            $verify = $parser->parseFile($dest . DIRECTORY_SEPARATOR . 'plugin.json', $slug);
            if (!$verify['ok']) {
                SafeDirectoryRemoval::removeIfInside($dest, $this->pluginsRoot);

                return 'Installed plugin failed final validation: ' . $verify['error'];
            }

            return null;
        } finally {
            SafeDirectoryRemoval::removeIfInside($work, dirname($work));
        }
    }

    private function locatePluginRoot(string $extractDir, string $expectedSlug): ?string
    {
        $extractReal = realpath($extractDir);
        if ($extractReal === false) {
            return null;
        }

        $candidates = [$extractReal];
        foreach (scandir($extractReal) ?: [] as $n) {
            if ($n === '.' || $n === '..') {
                continue;
            }
            $p = $extractReal . DIRECTORY_SEPARATOR . $n;
            if (is_dir($p)) {
                $candidates[] = $p;
            }
        }

        $match = null;
        foreach ($candidates as $dir) {
            $manifestPath = $dir . DIRECTORY_SEPARATOR . 'plugin.json';
            if (!is_file($manifestPath)) {
                continue;
            }
            try {
                $raw = json_decode((string) file_get_contents($manifestPath), true, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }
            if (!is_array($raw)) {
                continue;
            }
            $manifestSlug = strtolower(trim((string) ($raw['slug'] ?? '')));
            if ($manifestSlug !== $expectedSlug) {
                continue;
            }
            $r = realpath($dir);
            if ($r !== false) {
                $match = $r;
            }
        }

        return $match;
    }

    private function httpGetLimited(string $url, int $maxBytes): ?string
    {
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 120,
                'follow_location' => 1,
                'max_redirects' => 8,
                'header' => "User-Agent: Struxa-PluginInstall/1.0\r\n",
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
            $chunk = fread($h, 65_536);
            if ($chunk === false) {
                break;
            }
            $data .= $chunk;
        }
        fclose($h);
        if (strlen($data) >= $maxBytes || strlen($data) < 1) {
            return null;
        }

        return $data;
    }

    private function extractZipSafely(ZipArchive $zip, string $destDir): bool
    {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (!is_string($name) || $name === '' || str_contains($name, '..')) {
                return false;
            }
            $name = str_replace('\\', '/', $name);
            if (isset($name[0]) && $name[0] === '/') {
                return false;
            }
        }

        return $zip->extractTo($destDir);
    }

    private function copyDirectoryTree(string $source, string $dest): ?string
    {
        if (!@mkdir($dest, 0755, true) && !is_dir($dest)) {
            return 'Could not create plugin directory.';
        }
        $sourceReal = realpath($source);
        $destReal = realpath($dest);
        if ($sourceReal === false || $destReal === false) {
            return 'Path error while copying plugin.';
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        $sourcePrefix = $sourceReal . DIRECTORY_SEPARATOR;
        foreach ($it as $item) {
            if (!$item instanceof \SplFileInfo) {
                continue;
            }
            $pathname = $item->getPathname();
            if (!str_starts_with($pathname, $sourcePrefix)) {
                return 'Path error while copying plugin.';
            }
            $rel = substr($pathname, strlen($sourcePrefix));
            $target = $destReal . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            if ($item->isDir()) {
                if (!@mkdir($target, 0755, true) && !is_dir($target)) {
                    return 'Could not copy plugin folders.';
                }
            } else {
                $parent = dirname($target);
                if (!is_dir($parent) && !@mkdir($parent, 0755, true)) {
                    return 'Could not copy plugin files.';
                }
                if ($item->isLink()) {
                    return 'Plugin archive contains symbolic links.';
                }
                if (!@copy($item->getPathname(), $target)) {
                    return 'Could not copy plugin file.';
                }
            }
        }

        return null;
    }
}
