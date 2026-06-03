<?php

declare(strict_types=1);

namespace App\Theme;

use App\Dist\DistPackageFetcher;
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

    /** Full CMS repo archives (GitHub zipball) need a higher cap than single theme ZIPs. */
    private const MAX_MONOREPO_ZIP_BYTES = 100_000_000;

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

        $fetcher = new DistPackageFetcher($this->themes->projectRoot());
        $zipBody = $fetcher->fetchZip($entry->downloadUrl, self::MAX_ZIP_BYTES);
        if ($zipBody === null) {
            $hint = $fetcher->localZipHint($entry->downloadUrl);
            $suffix = $hint !== null
                ? ' Place the file at ' . $hint . ' on this server, or publish it to the URL in repo.json.'
                : '';

            return 'Could not download the theme package (size limit, network, or invalid response).' . $suffix;
        }

        return $this->installFromZipBody($zipBody, $slug, false);
    }

    /**
     * @param list<ThemeCatalogEntry> $catalogEntries
     */
    public function updateFromCatalogSlug(string $slug, array $catalogEntries): ?string
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
        if ($this->themes->findBySlug($slug) === null) {
            return 'Theme is not installed.';
        }
        if (!self::isDownloadUrlHostAllowed($entry->downloadUrl)) {
            return 'Download URL is not allowed.';
        }
        if (!class_exists(ZipArchive::class)) {
            return 'PHP zip extension (ZipArchive) is required to update themes from the catalog.';
        }

        $fetcher = new DistPackageFetcher($this->themes->projectRoot());
        $zipBody = $fetcher->fetchZip($entry->downloadUrl, self::MAX_ZIP_BYTES);
        if ($zipBody === null) {
            $hint = $fetcher->localZipHint($entry->downloadUrl);
            $suffix = $hint !== null
                ? ' Place the file at ' . $hint . ' on this server, or publish it to the URL in repo.json.'
                : '';

            return 'Could not download the theme package (size limit, network, or invalid response).' . $suffix;
        }

        return $this->installFromZipBody($zipBody, $slug, true);
    }

    public function updateFromGithubRepository(string $slug, string $owner, string $repo, string $ref = 'main'): ?string
    {
        $slug = strtolower(trim($slug));
        if (!ThemeManifest::isValidSlug($slug)) {
            return 'Invalid theme slug.';
        }
        if ($this->themes->findBySlug($slug) === null) {
            return 'Theme is not installed.';
        }

        $owner = trim($owner);
        $repo = trim($repo);
        $ref = trim($ref);
        if ($owner === '' || $repo === '' || $ref === '') {
            return 'Invalid GitHub repository.';
        }

        if (strtolower($owner) === 'struxa' && strtolower($repo) === 'struxa-theme') {
            $owner = 'struxa';
            $repo = 'struxa';
        }

        $isCmsMonorepo = strtolower($owner) === 'struxa' && strtolower($repo) === 'struxa';
        if ($isCmsMonorepo) {
            $bundled = $this->themes->projectRoot() . DIRECTORY_SEPARATOR . 'themes' . DIRECTORY_SEPARATOR . $slug;
            if (ThemeManifest::tryLoadRelaxedPath($bundled) !== null) {
                $err = $this->replaceInstalledThemeFromDirectory($bundled, $slug);
                if ($err === null) {
                    return null;
                }

                return $err;
            }
        }

        $urls = [
            sprintf(
                'https://codeload.github.com/%s/%s/zip/refs/heads/%s',
                rawurlencode($owner),
                rawurlencode($repo),
                rawurlencode($ref),
            ),
            sprintf(
                'https://github.com/%s/%s/archive/refs/heads/%s.zip',
                rawurlencode($owner),
                rawurlencode($repo),
                rawurlencode($ref),
            ),
        ];

        $lastErr = 'Could not download the theme package from GitHub.';
        foreach ($urls as $zipUrl) {
            if (!self::isDownloadUrlHostAllowed($zipUrl)) {
                continue;
            }
            if (!class_exists(ZipArchive::class)) {
                return 'PHP zip extension (ZipArchive) is required to update themes.';
            }
            $fetcher = new DistPackageFetcher($this->themes->projectRoot());
            $maxBytes = $isCmsMonorepo ? self::MAX_MONOREPO_ZIP_BYTES : self::MAX_ZIP_BYTES;
            $zipBody = $fetcher->fetchZip($zipUrl, $maxBytes);
            if ($zipBody === null) {
                continue;
            }
            $err = $this->installFromZipBody($zipBody, $slug, true);
            if ($err === null) {
                return null;
            }
            $lastErr = $err;
        }

        return $lastErr;
    }

    /**
     * Install a theme from raw ZIP bytes (catalog download or manual admin upload).
     * When $expectedCatalogSlug is set, manifest slug must match (catalog installs).
     */
    public function installFromZipBody(string $zipBody, ?string $expectedCatalogSlug = null, bool $replace = false): ?string
    {
        if (strlen($zipBody) > self::MAX_ZIP_BYTES) {
            return 'Theme package exceeds maximum size.';
        }
        if (!class_exists(ZipArchive::class)) {
            return 'PHP zip extension (ZipArchive) is required to install themes.';
        }

        $work = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'struxa-theme-' . bin2hex(random_bytes(8));
        if (!@mkdir($work, 0700, true) || !is_dir($work)) {
            return 'Could not create a temporary working directory.';
        }
        $zipPath = $work . DIRECTORY_SEPARATOR . 'package.zip';
        $extractDir = $work . DIRECTORY_SEPARATOR . 'extract';
        try {
            if (file_put_contents($zipPath, $zipBody) === false) {
                return 'Could not save the theme package.';
            }
            if (!@mkdir($extractDir, 0700, true)) {
                return 'Could not create extract directory.';
            }
            $zip = new ZipArchive();
            if ($zip->open($zipPath) !== true) {
                return 'File is not a valid ZIP archive.';
            }
            if (!$this->extractZipSafely($zip, $extractDir)) {
                $zip->close();

                return 'Theme archive contains unsafe paths and was rejected.';
            }
            $zip->close();
            if (!$this->verifyExtractContained($extractDir)) {
                return 'Theme archive extraction failed security checks.';
            }

            $preferSlug = $expectedCatalogSlug ?? null;
            $themeRoot = $this->locateRelaxedThemeRoot($extractDir, $preferSlug);
            if ($themeRoot === null) {
                return 'No valid theme (theme.json + views/ + assets/) was found in the archive.'
                    . ' For Struxa Vision on this server, run scripts/sync-struxa-theme-from-github.sh first,'
                    . ' or update from the catalog ZIP instead of the full CMS GitHub archive.';
            }
            $manifest = ThemeManifest::tryLoadRelaxedPath($themeRoot);
            if ($manifest === null) {
                return 'Theme package failed validation (check theme.json, parents, and settings schema).';
            }

            $slug = $manifest->slug;
            if ($expectedCatalogSlug !== null && $slug !== $expectedCatalogSlug) {
                return 'Archive theme slug "' . $slug . '" does not match catalog slug "' . $expectedCatalogSlug . '".';
            }
            if (!ThemeManifest::isValidSlug($slug)) {
                return 'Theme manifest slug is invalid.';
            }

            $dest = $this->themes->themesRoot() . DIRECTORY_SEPARATOR . $slug;
            $themesRootReal = realpath($this->themes->themesRoot());
            if ($themesRootReal === false) {
                return 'Could not resolve themes directory.';
            }

            $themeBackup = null;
            if ($replace) {
                if (!is_dir($dest)) {
                    return 'Theme is not installed.';
                }
                $destReal = realpath($dest);
                if ($destReal === false) {
                    return 'Could not resolve installed theme path.';
                }
                $themeBackup = $themesRootReal . DIRECTORY_SEPARATOR . '.theme-backup-' . $slug . '-' . bin2hex(random_bytes(6));
                $backupErr = $this->copyDirectoryTree($destReal, $themeBackup);
                if ($backupErr !== null) {
                    SafeDirectoryRemoval::removeIfInside($themeBackup, $themesRootReal);

                    return 'Could not back up the existing theme before update: ' . $backupErr;
                }
                $rm = SafeDirectoryRemoval::removeIfInside($destReal, $themesRootReal);
                if ($rm !== null) {
                    SafeDirectoryRemoval::removeIfInside($themeBackup, $themesRootReal);

                    return 'Could not remove the existing theme before update: ' . $rm;
                }
            } else {
                if ($this->themes->findBySlug($slug) !== null) {
                    return 'That theme is already installed. Use Update on the themes list or remove it first.';
                }

                if (is_dir($dest)) {
                    $installed = ThemeManifest::tryLoad($dest);
                    if ($installed !== null && $installed->slug === $slug) {
                        return 'That theme is already installed. Activate it from Themes.';
                    }

                    $entries = @scandir($dest);
                    if (is_array($entries)) {
                        $nonDot = array_values(array_filter($entries, static fn (string $n): bool => $n !== '.' && $n !== '..'));
                        if ($nonDot === []) {
                            $rm = SafeDirectoryRemoval::removeIfInside($dest, $this->themes->themesRoot());
                            if ($rm !== null && is_dir($dest)) {
                                return 'Target theme directory already exists.';
                            }
                        } else {
                            return 'Theme directory already exists but is not a valid installed theme. Remove themes/' . $slug . ' and retry.';
                        }
                    } else {
                        return 'Target theme directory already exists.';
                    }
                }
            }

            if (!@mkdir($dest, 0755, true) && !is_dir($dest)) {
                return 'Could not create theme directory.';
            }

            if (!@rename($themeRoot, $dest)) {
                $err = $this->copyDirectoryTree($themeRoot, $dest);
                if ($err !== null) {
                    if ($replace && $themeBackup !== null) {
                        $this->restoreThemeFromBackup($themeBackup, $dest, $themesRootReal);
                    }

                    return $err;
                }
                // Best effort cleanup only. Do not fail a successful install copy if temp cleanup fails.
                SafeDirectoryRemoval::removeIfInside($themeRoot, $extractDir);
            }

            $installed = ThemeManifest::tryLoad($dest);
            if ($installed === null) {
                SafeDirectoryRemoval::removeIfInside($dest, $themesRootReal);
                if ($replace && $themeBackup !== null) {
                    $this->restoreThemeFromBackup($themeBackup, $dest, $themesRootReal);

                    return 'Installed theme failed final validation. The previous theme was restored.';
                }

                return 'Installed theme failed final validation.';
            }

            if ($themeBackup !== null) {
                SafeDirectoryRemoval::removeIfInside($themeBackup, $themesRootReal);
            }

            $this->themes->clearDiscoverCache();

            return null;
        } finally {
            if (is_dir($work)) {
                SafeDirectoryRemoval::removeIfInside($work, dirname($work));
            }
        }
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

    private function locateRelaxedThemeRoot(string $extractDir, ?string $preferredSlug = null): ?string
    {
        $extractReal = realpath($extractDir);
        if ($extractReal === false) {
            return null;
        }

        $deep = $this->findThemePackageRecursive($extractReal, $preferredSlug, 10);
        if ($deep !== null) {
            return $deep;
        }

        return null;
    }

    /**
     * Walk extracted archives (GitHub zipballs, catalog ZIPs) to find a valid theme package root.
     */
    private function findThemePackageRecursive(string $root, ?string $preferredSlug, int $maxDepth): ?string
    {
        $preferredSlug = $preferredSlug !== null ? strtolower(trim($preferredSlug)) : '';
        $preferredMatch = null;
        $fallback = null;

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );
        } catch (\Throwable) {
            return null;
        }

        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo || !$item->isDir()) {
                continue;
            }
            if ($iterator->getDepth() > $maxDepth) {
                continue;
            }
            $path = $item->getPathname();
            if (ThemeManifest::tryLoadRelaxedPath($path) === null) {
                continue;
            }
            $real = realpath($path);
            if ($real === false) {
                continue;
            }
            if ($preferredSlug !== '') {
                $norm = str_replace('\\', '/', $real);
                if (preg_match('#/themes/' . preg_quote($preferredSlug, '#') . '/?$#', $norm) === 1) {
                    return $real;
                }
                if ($preferredMatch === null && basename($real) === $preferredSlug) {
                    $preferredMatch = $real;
                }
            }
            if ($fallback === null) {
                $fallback = $real;
            }
        }

        return $preferredMatch ?? $fallback;
    }

    /**
     * Replace an installed theme from themes/{slug}/ in the CMS project (Struxa monorepo on struxapoint.com).
     */
    private function replaceInstalledThemeFromDirectory(string $sourceDir, string $slug): ?string
    {
        $sourceReal = realpath($sourceDir);
        if ($sourceReal === false) {
            return 'Bundled theme directory is not readable.';
        }

        $manifest = ThemeManifest::tryLoadRelaxedPath($sourceReal);
        if ($manifest === null) {
            return 'Bundled theme failed validation (theme.json, views/, assets/).';
        }
        if ($manifest->slug !== $slug) {
            return 'Bundled theme slug does not match.';
        }

        $dest = $this->themes->themesRoot() . DIRECTORY_SEPARATOR . $slug;
        $themesRootReal = realpath($this->themes->themesRoot());
        if ($themesRootReal === false) {
            return 'Could not resolve themes directory.';
        }
        if (!is_dir($dest)) {
            return 'Theme is not installed.';
        }
        $destReal = realpath($dest);
        if ($destReal === false) {
            return 'Could not resolve installed theme path.';
        }

        // Monorepo updates use the same path for bundled + installed copy. Deleting $dest would
        // remove the copy source and leave an empty or invalid theme (Twig: page/show.twig missing).
        if ($sourceReal === $destReal) {
            if (ThemeManifest::tryLoad($destReal) === null) {
                return 'Installed theme is missing or invalid (theme.json, views/, assets/).'
                    . ' Re-sync themes/' . $slug . ' from the CMS package or GitHub, then retry.';
            }
            $this->themes->clearDiscoverCache();

            return null;
        }

        $staging = $themesRootReal . DIRECTORY_SEPARATOR . '.theme-staging-' . $slug . '-' . bin2hex(random_bytes(6));
        $stageErr = $this->copyDirectoryTree($sourceReal, $staging);
        if ($stageErr !== null) {
            SafeDirectoryRemoval::removeIfInside($staging, $themesRootReal);

            return 'Could not stage theme files for update: ' . $stageErr;
        }

        $backup = $themesRootReal . DIRECTORY_SEPARATOR . '.theme-backup-' . $slug . '-' . bin2hex(random_bytes(6));
        $backupErr = $this->copyDirectoryTree($destReal, $backup);
        if ($backupErr !== null) {
            SafeDirectoryRemoval::removeIfInside($staging, $themesRootReal);
            SafeDirectoryRemoval::removeIfInside($backup, $themesRootReal);

            return 'Could not back up the existing theme before update: ' . $backupErr;
        }

        $rm = SafeDirectoryRemoval::removeIfInside($destReal, $themesRootReal);
        if ($rm !== null) {
            SafeDirectoryRemoval::removeIfInside($staging, $themesRootReal);
            SafeDirectoryRemoval::removeIfInside($backup, $themesRootReal);

            return 'Could not remove the existing theme before update: ' . $rm;
        }
        if (!@mkdir($dest, 0755, true) && !is_dir($dest)) {
            SafeDirectoryRemoval::removeIfInside($staging, $themesRootReal);
            $this->restoreThemeFromBackup($backup, $dest, $themesRootReal);
            SafeDirectoryRemoval::removeIfInside($backup, $themesRootReal);

            return 'Could not create theme directory.';
        }

        $err = $this->copyDirectoryTree($staging, $dest);
        SafeDirectoryRemoval::removeIfInside($staging, $themesRootReal);
        if ($err !== null) {
            $this->restoreThemeFromBackup($backup, $dest, $themesRootReal);
            SafeDirectoryRemoval::removeIfInside($backup, $themesRootReal);

            return $err;
        }

        if (ThemeManifest::tryLoad($dest) === null) {
            SafeDirectoryRemoval::removeIfInside($dest, $themesRootReal);
            $this->restoreThemeFromBackup($backup, $dest, $themesRootReal);
            SafeDirectoryRemoval::removeIfInside($backup, $themesRootReal);

            return 'Installed theme failed final validation. The previous theme was restored.';
        }

        SafeDirectoryRemoval::removeIfInside($backup, $themesRootReal);
        $this->themes->clearDiscoverCache();

        return null;
    }

    private function restoreThemeFromBackup(string $backupDir, string $destPath, string $themesRootReal): void
    {
        $backupReal = realpath($backupDir);
        if ($backupReal === false || !is_dir($backupReal)) {
            return;
        }
        if (!@mkdir($destPath, 0755, true) && !is_dir($destPath)) {
            return;
        }
        $this->copyDirectoryTree($backupReal, $destPath);
        SafeDirectoryRemoval::removeIfInside($backupReal, $themesRootReal);
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
        $sourcePrefix = $sourceReal . DIRECTORY_SEPARATOR;
        foreach ($it as $item) {
            if (!$item instanceof \SplFileInfo) {
                continue;
            }
            $pathname = $item->getPathname();
            if (!str_starts_with($pathname, $sourcePrefix)) {
                return 'Path error while copying theme.';
            }
            $rel = substr($pathname, strlen($sourcePrefix));
            $target = $destReal . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
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
