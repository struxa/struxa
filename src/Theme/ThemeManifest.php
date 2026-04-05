<?php

declare(strict_types=1);

namespace App\Theme;

use App\Manifest\ManifestMeta;

/**
 * Parsed theme.json (validated subset).
 */
final class ThemeManifest
{
    private const MAX_NAME_LEN = 120;

    private const MAX_AUTHOR_LEN = 120;

    private const MAX_VERSION_LEN = 32;

    private const MAX_DESCRIPTION_LEN = 2000;

    private const MAX_SETTING_KEY_LEN = 64;

    private const MAX_PARENTS = ThemeHttpConfig::MAX_PARENT_DEPTH;

    /**
     * @param array<string, mixed>|null $settingsSchema keyed setting id => definition (type, label, default, …)
     * @param list<string>              $parents ancestor slugs root-first (furthest parent first), nearest parent last
     * @param list<string>              $tags    marketplace / discovery tags
     */
    public function __construct(
        public readonly string $name,
        public readonly string $slug,
        public readonly string $version,
        public readonly string $author,
        public readonly string $description,
        public readonly ?string $screenshot,
        public readonly ?array $settingsSchema,
        public readonly array $parents,
        public readonly string $themeRootPath,
        public readonly ?string $homepage = null,
        public readonly ?string $repositoryUrl = null,
        public readonly ?string $license = null,
        public readonly ?string $supportUrl = null,
        public readonly ?string $category = null,
        public readonly array $tags = [],
        public readonly ?string $minCmsVersion = null,
        public readonly ?string $testedUpTo = null,
    ) {
    }

    /**
     * @return array<string, mixed> Twig/admin safe (paths stay server-side only except screenshot relative path)
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'slug' => $this->slug,
            'version' => $this->version,
            'author' => $this->author,
            'description' => $this->description,
            'screenshot' => $this->screenshot,
            'settings_schema' => $this->settingsSchema ?? [],
            'parents' => $this->parents,
            'homepage' => $this->homepage,
            'repository_url' => $this->repositoryUrl,
            'license' => $this->license,
            'support_url' => $this->supportUrl,
            'category' => $this->category,
            'tags' => $this->tags,
            'min_cms_version' => $this->minCmsVersion,
            'tested_up_to' => $this->testedUpTo,
        ];
    }

    public static function isValidSlug(string $slug): bool
    {
        return $slug !== '' && (bool) preg_match('/^[a-z0-9][a-z0-9\-]{0,62}$/', $slug);
    }

    /**
     * @return self|null null if invalid or unreadable
     */
    public static function tryLoad(string $themeDirectory): ?self
    {
        return self::tryLoadInternal($themeDirectory, true);
    }

    /**
     * Same as {@see tryLoad} but does not require the theme folder basename to match `slug`
     * (GitHub ZIP archives use e.g. `repo-main/` while theme.json says `slug`).
     */
    public static function tryLoadRelaxedPath(string $themeDirectory): ?self
    {
        return self::tryLoadInternal($themeDirectory, false);
    }

    /**
     * @return self|null null if invalid or unreadable
     */
    private static function tryLoadInternal(string $themeDirectory, bool $requireBasenameMatch): ?self
    {
        $themeDirectory = rtrim($themeDirectory, '/\\');
        $jsonPath = $themeDirectory . DIRECTORY_SEPARATOR . 'theme.json';
        if (!is_readable($jsonPath)) {
            return null;
        }
        $raw = file_get_contents($jsonPath);
        if ($raw === false) {
            return null;
        }
        try {
            /** @var mixed $data */
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($data)) {
            return null;
        }

        $name = self::boundedStr($data, 'name', self::MAX_NAME_LEN);
        $slug = self::boundedStr($data, 'slug', 63);
        $version = self::boundedStr($data, 'version', self::MAX_VERSION_LEN);
        $author = self::boundedStr($data, 'author', self::MAX_AUTHOR_LEN);
        $description = self::boundedStr($data, 'description', self::MAX_DESCRIPTION_LEN);
        if ($name === '' || $slug === '' || $version === '' || $author === '') {
            return null;
        }
        $slug = strtolower($slug);
        if (!self::isValidSlug($slug)) {
            return null;
        }
        if (!self::isReasonableVersion($version)) {
            return null;
        }

        $base = basename($themeDirectory);
        if ($requireBasenameMatch && $base !== $slug) {
            return null;
        }

        $views = $themeDirectory . DIRECTORY_SEPARATOR . 'views';
        $assets = $themeDirectory . DIRECTORY_SEPARATOR . 'assets';
        if (!is_dir($views) || !is_dir($assets)) {
            return null;
        }

        $screenshot = self::nullableScreenshot($data);
        $settingsSchema = self::parseSettingsSchema($data['settings'] ?? null);
        if ($settingsSchema === false) {
            return null;
        }

        $parents = self::parseParents($data['parents'] ?? null, $slug);
        if ($parents === null) {
            return null;
        }

        $minCms = self::boundedStr($data, 'min_cms_version', self::MAX_VERSION_LEN);
        if ($minCms === '') {
            $minCms = null;
        }

        return new self(
            $name,
            $slug,
            $version,
            $author,
            $description,
            $screenshot,
            $settingsSchema,
            $parents,
            $themeDirectory,
            homepage: ManifestMeta::httpUrlOrNull(self::boundedStrOrNull($data, 'homepage', 500)),
            repositoryUrl: ManifestMeta::httpUrlOrNull(self::boundedStrOrNull($data, 'repository_url', 500)),
            license: self::boundedStr($data, 'license', 120) ?: null,
            supportUrl: ManifestMeta::httpUrlOrNull(self::boundedStrOrNull($data, 'support_url', 500)),
            category: self::boundedStr($data, 'category', 80) ?: null,
            tags: ManifestMeta::parseTags($data['tags'] ?? null),
            minCmsVersion: $minCms,
            testedUpTo: self::boundedStr($data, 'tested_up_to', self::MAX_VERSION_LEN) ?: null,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function boundedStrOrNull(array $data, string $key, int $maxBytes): ?string
    {
        $v = self::boundedStr($data, $key, $maxBytes);

        return $v === '' ? null : $v;
    }

    /**
     * @return list<string>|null
     */
    private static function parseParents(mixed $raw, string $ownSlug): ?array
    {
        if ($raw === null) {
            return [];
        }
        if (!is_array($raw)) {
            return null;
        }
        $out = [];
        $seen = [];
        foreach ($raw as $p) {
            if (count($out) >= self::MAX_PARENTS) {
                break;
            }
            if (!is_string($p)) {
                return null;
            }
            $s = strtolower(trim($p));
            if ($s === '' || !self::isValidSlug($s) || $s === $ownSlug) {
                return null;
            }
            if (isset($seen[$s])) {
                continue;
            }
            $seen[$s] = true;
            $out[] = $s;
        }

        return $out;
    }

    /**
     * @return array<string, mixed>|false|null null = omit schema; false = invalid manifest
     */
    private static function parseSettingsSchema(mixed $raw): array|false|null
    {
        if ($raw === null) {
            return null;
        }
        if (!is_array($raw)) {
            return false;
        }
        $out = [];
        foreach ($raw as $key => $def) {
            if (!is_string($key) || $key === '' || strlen($key) > self::MAX_SETTING_KEY_LEN) {
                return false;
            }
            if (!preg_match('/^[a-z][a-z0-9_]*$/', $key)) {
                return false;
            }
            if (!is_array($def)) {
                return false;
            }
            $out[$key] = $def;
        }

        return $out;
    }

    private static function isReasonableVersion(string $v): bool
    {
        if (strlen($v) > self::MAX_VERSION_LEN) {
            return false;
        }

        return (bool) preg_match('/^v?[0-9]+(?:\.[0-9]+){0,3}[a-z0-9.\-]*$/i', $v);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function boundedStr(array $data, string $key, int $maxBytes): string
    {
        $v = $data[$key] ?? '';
        if (!is_string($v)) {
            return '';
        }
        $v = trim($v);

        return mb_strlen($v) > $maxBytes ? '' : $v;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function nullableScreenshot(array $data): ?string
    {
        $v = self::boundedStr($data, 'screenshot', 255);
        if ($v === '') {
            return null;
        }
        if (str_contains($v, '\\') || str_contains($v, '..') || str_starts_with($v, '/')) {
            return null;
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $v) === 1) {
            return null;
        }

        return $v;
    }
}
