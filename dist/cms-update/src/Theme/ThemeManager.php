<?php

declare(strict_types=1);

namespace App\Theme;

use App\Filesystem\SafeDirectoryRemoval;
use App\Settings;

/**
 * Discovers themes on disk and resolves the active theme views directory.
 */
final class ThemeManager
{
    /** @var list<ThemeManifest>|null */
    private ?array $discoverCache = null;

    public function __construct(
        private readonly string $projectRoot,
    ) {
    }

    public function themesRoot(): string
    {
        return $this->projectRoot . DIRECTORY_SEPARATOR . ThemeHttpConfig::THEMES_DIRECTORY;
    }

    /**
     * @return list<ThemeManifest>
     */
    public function clearDiscoverCache(): void
    {
        $this->discoverCache = null;
    }

    public function discover(): array
    {
        if ($this->discoverCache !== null) {
            return $this->discoverCache;
        }

        $root = $this->themesRoot();
        if (!is_dir($root)) {
            $this->discoverCache = [];

            return $this->discoverCache;
        }
        $rootReal = realpath($root);
        if ($rootReal === false) {
            $this->discoverCache = [];

            return $this->discoverCache;
        }

        $out = [];
        $themesPrefix = rtrim($rootReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        foreach (scandir($root) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $root . DIRECTORY_SEPARATOR . $name;
            if (!is_dir($path)) {
                continue;
            }
            $pathReal = realpath($path);
            if ($pathReal === false || !str_starts_with($pathReal, $themesPrefix)) {
                continue;
            }
            $m = ThemeManifest::tryLoad($path);
            if ($m !== null) {
                $out[] = $m;
            }
        }

        usort($out, static fn (ThemeManifest $a, ThemeManifest $b): int => strcasecmp($a->name, $b->name));

        $this->discoverCache = $out;

        return $this->discoverCache;
    }

    public function findBySlug(string $slug): ?ThemeManifest
    {
        $slug = strtolower(trim($slug));
        if (!ThemeManifest::isValidSlug($slug)) {
            return null;
        }
        $path = $this->themesRoot() . DIRECTORY_SEPARATOR . $slug;
        if (!is_dir($path)) {
            return null;
        }
        $rootReal = realpath($this->themesRoot());
        $pathReal = realpath($path);
        if ($rootReal === false || $pathReal === false) {
            return null;
        }
        $themesPrefix = rtrim($rootReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (!str_starts_with($pathReal, $themesPrefix)) {
            return null;
        }

        return ThemeManifest::tryLoad($path);
    }

    public function activeSlug(): string
    {
        $s = Settings::get('active_theme', ThemeHttpConfig::FALLBACK_THEME_SLUG);
        $s = $s !== null && $s !== '' ? strtolower(trim($s)) : ThemeHttpConfig::FALLBACK_THEME_SLUG;

        return ThemeManifest::isValidSlug($s) ? $s : ThemeHttpConfig::FALLBACK_THEME_SLUG;
    }

    /**
     * Absolute path to active theme views/, or null if broken (caller should fall back).
     */
    public function viewsPathForActive(): ?string
    {
        return $this->viewsPathForSlug($this->activeSlug());
    }

    public function viewsPathForSlug(string $slug): ?string
    {
        $m = $this->findBySlug($slug);
        if ($m === null) {
            return null;
        }

        return $this->realViewsDirectory($m);
    }

    /**
     * View directories for Twig loader order after core: active theme first, then parents from nearest to
     * furthest. Twig’s FilesystemLoader returns the first path that contains the file, so the active
     * theme overrides its parents; parents fill in templates the child does not define.
     *
     * @return list<string>
     */
    public function viewPathTailsForManifest(ThemeManifest $manifest): array
    {
        $parentDirs = [];
        foreach ($manifest->parents as $parentSlug) {
            if (count($parentDirs) >= ThemeHttpConfig::MAX_PARENT_DEPTH) {
                break;
            }
            $p = $this->findBySlug($parentSlug);
            if ($p === null || $p->slug === $manifest->slug) {
                continue;
            }
            $dir = $this->realViewsDirectory($p);
            if ($dir !== null) {
                $parentDirs[] = $dir;
            }
        }

        $self = $this->realViewsDirectory($manifest);
        $out = [];
        if ($self !== null) {
            $out[] = $self;
        }
        foreach (array_reverse($parentDirs) as $dir) {
            $out[] = $dir;
        }

        return $out;
    }

    public function assetsPathForSlug(string $slug): ?string
    {
        $m = $this->findBySlug($slug);
        if ($m === null) {
            return null;
        }
        $assets = $m->themeRootPath . DIRECTORY_SEPARATOR . 'assets';
        if (!is_dir($assets)) {
            return null;
        }
        $real = realpath($assets);

        return $real !== false ? $real : null;
    }

    /**
     * Absolute filesystem path to screenshot file, if present and safe.
     */
    public function screenshotAbsolutePath(ThemeManifest $manifest): ?string
    {
        if ($manifest->screenshot === null) {
            return null;
        }
        $segments = ThemeFilesystem::safeRelativePathSegments($manifest->screenshot);
        if ($segments === []) {
            return null;
        }
        $rel = implode(DIRECTORY_SEPARATOR, $segments);
        $full = $manifest->themeRootPath . DIRECTORY_SEPARATOR . $rel;
        $real = realpath($full);
        $rootReal = realpath($manifest->themeRootPath);
        if ($real === false || $rootReal === false || !is_file($real)) {
            return null;
        }
        if (!ThemeFilesystem::pathIsInsideDirectory($real, $rootReal)) {
            return null;
        }

        return $real;
    }

    /**
     * Remove an installed theme directory from disk. Refuses the active theme, the {@see ThemeHttpConfig::FALLBACK_THEME_SLUG}
     * bundle, or any theme still referenced as a parent by another installed theme.
     *
     * @return string|null Error message for the user, or null on success
     */
    public function removeInstalledTheme(string $slug): ?string
    {
        $slug = strtolower(trim($slug));
        if (!ThemeManifest::isValidSlug($slug)) {
            return 'Invalid theme.';
        }

        if ($slug === ThemeHttpConfig::FALLBACK_THEME_SLUG) {
            return 'The default theme cannot be removed.';
        }

        if ($this->activeSlug() === $slug) {
            return 'Switch to another theme before removing this one.';
        }

        foreach ($this->discover() as $t) {
            if ($t->slug === $slug) {
                continue;
            }
            foreach ($t->parents as $p) {
                if (strtolower(trim($p)) === $slug) {
                    return 'Another installed theme extends this one; change that theme’s parents first.';
                }
            }
        }

        $manifest = $this->findBySlug($slug);
        if ($manifest === null) {
            return 'That theme is not installed.';
        }

        $err = SafeDirectoryRemoval::removeIfInside($manifest->themeRootPath, $this->themesRoot());
        if ($err !== null) {
            return $err;
        }

        $this->clearDiscoverCache();

        return null;
    }

    private function realViewsDirectory(ThemeManifest $manifest): ?string
    {
        $views = $manifest->themeRootPath . DIRECTORY_SEPARATOR . 'views';
        if (!is_dir($views)) {
            return null;
        }
        $real = realpath($views);

        return $real !== false ? $real : null;
    }
}
