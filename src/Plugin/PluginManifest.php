<?php

declare(strict_types=1);

namespace App\Plugin;

use App\Manifest\ManifestMeta;

/**
 * Parsed plugin.json (filesystem manifest).
 */
final class PluginManifest
{
    /**
     * @param array<string, mixed>|null $autoload psr4 map: namespace => relative path
     * @param list<string>              $tags     marketplace / discovery tags
     */
    public function __construct(
        public readonly string $name,
        public readonly string $slug,
        public readonly string $version,
        public readonly string $author,
        public readonly string $description,
        public readonly ?string $requiresCmsVersion,
        public readonly ?string $requiresPhp,
        public readonly ?string $mainClass,
        public readonly bool $enabledByDefault,
        /** @var array<string, string>|null */
        public readonly ?array $autoloadPsr4,
        public readonly ?string $homepage = null,
        public readonly ?string $repositoryUrl = null,
        public readonly ?string $license = null,
        public readonly ?string $supportUrl = null,
        public readonly ?string $category = null,
        public readonly array $tags = [],
        public readonly ?string $testedUpTo = null,
        /**
         * Optional slug of another plugin under {@code /plugins/{slug}/} that should own this plugin's
         * admin sidebar entry as a nested item (Extensions → parent name → child links).
         */
        public readonly ?string $parentPluginSlug = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data, string $directorySlug): self
    {
        $slug = trim((string) ($data['slug'] ?? ''));
        if ($slug === '') {
            $slug = $directorySlug;
        }

        $autoload = $data['autoload'] ?? null;
        $psr4 = null;
        if (is_array($autoload) && isset($autoload['psr4']) && is_array($autoload['psr4'])) {
            $psr4 = [];
            foreach ($autoload['psr4'] as $ns => $path) {
                if (is_string($ns) && is_string($path)) {
                    $psr4[$ns] = $path;
                }
            }
            if ($psr4 === []) {
                $psr4 = null;
            }
        }

        $reqCms = isset($data['requires_cms_version']) && is_string($data['requires_cms_version']) && $data['requires_cms_version'] !== ''
            ? trim($data['requires_cms_version'])
            : null;
        if ($reqCms === null && isset($data['min_cms_version']) && is_string($data['min_cms_version']) && $data['min_cms_version'] !== '') {
            $reqCms = trim($data['min_cms_version']);
        }

        return new self(
            name: trim((string) ($data['name'] ?? '')),
            slug: $slug,
            version: trim((string) ($data['version'] ?? '0.0.0')),
            author: trim((string) ($data['author'] ?? '')),
            description: trim((string) ($data['description'] ?? '')),
            requiresCmsVersion: $reqCms,
            requiresPhp: isset($data['requires_php']) && is_string($data['requires_php']) && $data['requires_php'] !== ''
                ? trim($data['requires_php'])
                : null,
            mainClass: isset($data['main_class']) && is_string($data['main_class']) && $data['main_class'] !== ''
                ? trim($data['main_class'])
                : null,
            enabledByDefault: !empty($data['enabled_by_default']),
            autoloadPsr4: $psr4,
            homepage: ManifestMeta::httpUrlOrNull(self::optStr($data, 'homepage', 500)),
            repositoryUrl: ManifestMeta::httpUrlOrNull(self::optStr($data, 'repository_url', 500)),
            license: self::optStr($data, 'license', 120),
            supportUrl: ManifestMeta::httpUrlOrNull(self::optStr($data, 'support_url', 500)),
            category: self::optStr($data, 'category', 80),
            tags: ManifestMeta::parseTags($data['tags'] ?? null),
            testedUpTo: isset($data['tested_up_to']) && is_string($data['tested_up_to']) && $data['tested_up_to'] !== ''
                ? trim($data['tested_up_to'])
                : null,
            parentPluginSlug: self::parseParentPluginSlug($data, $slug),
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function parseParentPluginSlug(array $data, string $selfSlug): ?string
    {
        $raw = $data['parent_plugin'] ?? $data['parentPlugin'] ?? null;
        if (!is_string($raw)) {
            return null;
        }
        $p = trim($raw);
        if ($p === '' || strcasecmp($p, $selfSlug) === 0) {
            return null;
        }
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $p)) {
            return null;
        }

        return $p;
    }

    /**
     * @return array<string, mixed>
     */
    public function marketplaceMeta(): array
    {
        return [
            'homepage' => $this->homepage,
            'repository_url' => $this->repositoryUrl,
            'license' => $this->license,
            'support_url' => $this->supportUrl,
            'category' => $this->category,
            'tags' => $this->tags,
            'min_cms_version' => $this->requiresCmsVersion,
            'tested_up_to' => $this->testedUpTo,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function optStr(array $data, string $key, int $max): ?string
    {
        $v = $data[$key] ?? null;
        if (!is_string($v)) {
            return null;
        }
        $v = trim($v);

        return $v === '' || strlen($v) > $max ? null : $v;
    }

}
