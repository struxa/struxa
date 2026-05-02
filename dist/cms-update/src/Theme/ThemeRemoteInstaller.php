<?php

declare(strict_types=1);

namespace App\Theme;

use App\Filesystem\SafeDirectoryRemoval;
use ZipArchive;

/**
 * Downloads a theme ZIP from a catalog URL, validates it, and installs under themes/{slug}/.
 */
final class ThemeRemoteInstaller
{
    private const DEFAULT_ALLOWED_HOSTS = [
        'github.com',
        'www.github.com',
        'raw.githubusercontent.com',
        'codeload.github.com',
        'objects.githubusercontent.com',
        'struxapoint.com',
        'www.struxapoint.com',
    ];

    private const MAX_ZIP_BYTES = 35_000_000;

    public function __construct(
        private readonly ThemeManager $themes,
    ) {
    }

    public static function isDownloadUrlHostAllowed(string $httpsUrl): bool
    {
        $p = parse_url($httpsUrl);
        if (($p['scheme'] ?? '') !== 'https') {
            return false;
        }
        $host = strtolower((string) ($p['host'] ?? ''));
        if ($host === '') {
            return false;
        }
        $extra = trim((string) ($_ENV['STRUXA_THEME_DOWNLOAD_HOSTS'] ?? getenv('STRUXA_THEME_DOWNLOAD_HOSTS') ?? ''));
        $allow = self::DEFAULT_ALLOWED_HOSTS;
        if ($extra !== '') {
            foreach (array_map('trim', explode(',', $extra)) as $h) {
                if ($h !== '') {
                    $allow[] = strtolower($h);
                }
            }
        }
        $allow = array_values(array_unique($allow));

        return in_array($host, $allow, true);
    }

    /**
     * @param list<ThemeCatalogEntry> $catalogEntries
     */
    public function installFromCatalogSlug(string $slug, array $catalogEntries): ?string
    {
        $slug = strtolower(trim($slug));
        if (!ThemeManifest::isValidSlug($slug)) {
            return 'Invalid theme slug.';
        }
        $entry = null;
        foreach ($catalogEntries as $e) {
            if ($e->slug === $slug) {
                $entry = $e;
                break;
            }
        }
        if ($entry === null) {
            return 'That theme is not in the current catalog.';
        }
        if (!self::isDownloadUrlHostAllowed($entry->downloadUrl)) {
            return 'Download URL is not allowed.';
        }
        if (!class_exists(ZipArchive::class)) {
            return 'PHP zip extension (ZipArchive) is required to install themes from the catalog.';
        }
        if ($this->themes->findBySlug($slug) !== null) {
            return 'That theme is already installed. Remove it first if you want to replace it.';
        }

        $zipBody = $this->httpGetLimited($entry->downloadUrl, self::MAX_ZIP_BYTES);
        if ($zipBody === null) {
            return 'Could not download the theme package (size limit, network, or invalid response).';
        }

        $work = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'struxa-theme-' . bin2hex(random_bytes(8));
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

                return 'Theme archive contains unsafe paths and was rejected.';
            }
            $zip->close();
            if (!$this->verifyExtractContained($extractDir)) {
                return 'Theme archive extraction failed security checks.';
            }

            $themeRoot = $this->locateRelaxedThemeRoot($extractDir);
            if ($themeRoot === null) {
                return 'No valid theme (theme.json + views/ + assets/) was found in the archive.';
            }
            $manifest = ThemeManifest::tryLoadRelaxedPath($themeRoot);
            if ($manifest === null) {
                return 'Theme package failed validation (check theme.json, parents, and settings schema).';
            }
            if ($manifest->slug !== $slug) {
                return 'Archive theme slug "' . $manifest->slug . '" does not match catalog slug "' . $slug . '".';
            }

            $dest = $this->themes->themesRoot() . DIRECTORY_SEPARATOR . $slug;
            if (is_dir($dest)) {
                return 'Target theme directory already exists.';
            }
            if (!@rename($themeRoot, $dest)) {
                $err = $this->copyDirectoryTree($themeRoot, $dest);
                if ($err !== null) {
                    return $err;
                }
                $rm = SafeDirectoryRemoval::removeIfInside($themeRoot, $extractDir);
                if ($rm !== null) {
                    SafeDirectoryRemoval::removeIfInside($dest, $this->themes->themesRoot());

                    return 'Could not finalize theme install.';
                }
            }

            $installed = ThemeManifest::tryLoad($dest);
            if ($installed === null) {
                SafeDirectoryRemoval::removeIfInside($dest, $this->themes->themesRoot());

                return 'Installed theme failed final validation.';
            }

            $this->themes->clearDiscoverCache();

            return null;
        } finally {
            if (is_dir($work)) {
                SafeDirectoryRemoval::removeIfInside($work, dirname($work));
            }
        }
    }

    private function httpGetLimited(string $url, int $maxBytes): ?string
    {
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 120,
                'follow_location' => 1,
                'max_redirects' => 8,
                'header' => "User-Agent: Struxa-ThemeInstall/1.0\r\n",
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
        if (strlen($data) >= $maxBytes) {
            return null;
        }
        if ($data === '') {
            return null;
        }

        return $data;
    }

    private function extractZipSafely(ZipArchive $zip, string $destDir): bool
    {
        $destReal = realpath($destDir);
        if ($destReal === false) {
            return false;
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (!is_string($name) || $name === '') {
                return false;
            }
            if (str_contains($name, '..')) {
                return false;
            }
            $name = str_replace('\\', '/', $name);
            if ($name[0] === '/') {
                return false;
            }
        }

        if (!$zip->extractTo($destDir)) {
            return false;
        }

        return true;
    }

    private function verifyExtractContained(string $extractDir): bool
    {
        $base = realpath($extractDir);
        if ($base === false || !is_dir($base)) {
            return false;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($extractDir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            $rp = $f->getRealPath();
            if ($rp === false) {
                return false;
            }
            if ($rp !== $base && !str_starts_with($rp, $base . DIRECTORY_SEPARATOR)) {
                return false;
            }
        }

        return true;
    }

    private function locateRelaxedThemeRoot(string $extractDir): ?string
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

        foreach ($candidates as $dir) {
            if (ThemeManifest::tryLoadRelaxedPath($dir) !== null) {
                $r = realpath($dir);

                return $r !== false ? $r : null;
            }
        }

        return null;
    }

    private function copyDirectoryTree(string $source, string $dest): ?string
    {
        if (!@mkdir($dest, 0755, true) && !is_dir($dest)) {
            return 'Could not create theme directory.';
        }
        $sourceReal = realpath($source);
        $destReal = realpath($dest);
        if ($sourceReal === false || $destReal === false) {
            return 'Path error while copying theme.';
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $item) {
            /** @var \SplFileInfo $item */
            $sub = $item->getSubPathname();
            $target = $destReal . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $sub);
            if ($item->isDir()) {
                if (!@mkdir($target, 0755, true) && !is_dir($target)) {
                    return 'Could not copy theme folders.';
                }
            } else {
                $parent = dirname($target);
                if (!is_dir($parent) && !@mkdir($parent, 0755, true)) {
                    return 'Could not copy theme files.';
                }
                if ($item->isLink()) {
                    return 'Theme archive contains symbolic links.';
                }
                if (!@copy($item->getPathname(), $target)) {
                    return 'Could not copy theme file.';
                }
            }
        }

        return null;
    }
}
