<?php

declare(strict_types=1);

namespace App\Plugin;

use App\Filter\FilterHook;
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
        /**
         * When true, every {@see PluginBootContext::registerAdminNavItem} link is grouped under this
         * plugin's {@code name} in the sidebar (Extensions → …), like multiple child plugins under a parent,
         * without splitting the codebase across folders. Mutually exclusive with {@see parentPluginSlug}
     * in {@code plugin.json} (activation validates).
     */
        public readonly bool $nestedAdminNav = false,
        /** @var array<string, string> slug => semver constraint */
        public readonly array $requiresPlugins = [],
        /** @var list<string> */
        public readonly array $conflicts = [],
        /** @var list<string> PHP extension names */
        public readonly array $requiresExt = [],
        /** @var list<string> */
        public readonly array $capabilities = [],
        /** @var list<string> filter hook names from {@see FilterHook} */
        public readonly array $hookFilters = [],
        /** @var list<string> event short names from {@see PluginKnownEvents} */
        public readonly array $hookEvents = [],
        public readonly string $databaseMigrationsPath = 'migrations',
        /** @var list<string> tables this plugin owns (documentation / preflight) */
        public readonly array $databaseTables = [],
        public readonly ?string $maxCmsVersion = null,
        public readonly bool $loadPublic = true,
        public readonly bool $loadAdmin = true,
        public readonly bool $loadCli = true,
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
            nestedAdminNav: self::parseNestedAdminNav($data),
            requiresPlugins: self::parseRequiresPlugins($data),
            conflicts: self::parseSlugList($data['conflicts'] ?? null, $slug),
            requiresExt: self::parseStringList($data['requires_ext'] ?? $data['requiresExt'] ?? null),
            capabilities: self::parseStringList($data['capabilities'] ?? null),
            hookFilters: self::parseHookFilters($data['hooks'] ?? null),
            hookEvents: self::parseHookEvents($data['hooks'] ?? null),
            databaseMigrationsPath: self::parseDatabaseMigrationsPath($data['database'] ?? null),
            databaseTables: self::parseDatabaseTables($data['database'] ?? null),
            maxCmsVersion: self::parseOptionalVersion($data, 'max_cms_version', 'maxCmsVersion'),
            loadPublic: self::parseLoadFlag($data['load'] ?? null, 'public', true),
            loadAdmin: self::parseLoadFlag($data['load'] ?? null, 'admin', true),
            loadCli: self::parseLoadFlag($data['load'] ?? null, 'cli', true),
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function parseNestedAdminNav(array $data): bool
    {
        return !empty($data['nested_admin_nav']) || !empty($data['nestedAdminNav']);
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
    public function contractSummary(): array
    {
        return [
            'requires_plugins' => $this->requiresPlugins,
            'conflicts' => $this->conflicts,
            'requires_ext' => $this->requiresExt,
            'capabilities' => $this->capabilities,
            'hooks' => [
                'filters' => $this->hookFilters,
                'events' => $this->hookEvents,
            ],
            'database' => [
                'migrations' => $this->databaseMigrationsPath,
                'tables' => $this->databaseTables,
            ],
            'max_cms_version' => $this->maxCmsVersion,
            'load' => [
                'public' => $this->loadPublic,
                'admin' => $this->loadAdmin,
                'cli' => $this->loadCli,
            ],
        ];
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

    /**
     * @param array<string, mixed> $data
     * @return array<string, string>
     */
    private static function parseRequiresPlugins(array $data): array
    {
        $raw = $data['requires_plugins'] ?? $data['requiresPlugins'] ?? null;
        if ($raw === null) {
            return [];
        }
        $out = [];
        if (is_array($raw) && array_is_list($raw)) {
            foreach ($raw as $slug) {
                if (!is_string($slug)) {
                    continue;
                }
                $s = trim($slug);
                if ($s !== '' && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $s)) {
                    $out[$s] = '*';
                }
            }

            return $out;
        }
        if (!is_array($raw)) {
            return [];
        }
        foreach ($raw as $slug => $constraint) {
            if (!is_string($slug)) {
                continue;
            }
            $s = trim($slug);
            if ($s === '' || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $s)) {
                continue;
            }
            $c = is_string($constraint) ? trim($constraint) : '*';
            $out[$s] = $c !== '' ? $c : '*';
        }

        return $out;
    }

    /**
     * @param mixed $raw
     * @return list<string>
     */
    private static function parseStringList(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $item) {
            if (!is_string($item)) {
                continue;
            }
            $s = trim($item);
            if ($s !== '') {
                $out[] = $s;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param mixed $raw
     * @return list<string>
     */
    private static function parseSlugList(mixed $raw, string $selfSlug): array
    {
        $list = self::parseStringList($raw);
        $out = [];
        foreach ($list as $slug) {
            if (strcasecmp($slug, $selfSlug) === 0) {
                continue;
            }
            if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
                $out[] = $slug;
            }
        }

        return $out;
    }

    /**
     * @param mixed $hooks
     * @return list<string>
     */
    private static function parseHookFilters(mixed $hooks): array
    {
        if (!is_array($hooks)) {
            return [];
        }
        $raw = $hooks['filters'] ?? null;

        return self::parseStringList($raw);
    }

    /**
     * @param mixed $hooks
     * @return list<string>
     */
    private static function parseHookEvents(mixed $hooks): array
    {
        if (!is_array($hooks)) {
            return [];
        }
        $raw = $hooks['events'] ?? null;

        return self::parseStringList($raw);
    }

    /**
     * @param mixed $database
     */
    private static function parseDatabaseMigrationsPath(mixed $database): string
    {
        if (!is_array($database)) {
            return 'migrations';
        }
        $path = $database['migrations'] ?? 'migrations';
        if (!is_string($path) || trim($path) === '') {
            return 'migrations';
        }
        $path = trim(str_replace('\\', '/', $path), '/');
        if (str_contains($path, '..') || str_starts_with($path, '/')) {
            return 'migrations';
        }

        return $path;
    }

    /**
     * @param mixed $database
     * @return list<string>
     */
    private static function parseDatabaseTables(mixed $database): array
    {
        if (!is_array($database)) {
            return [];
        }

        return self::parseStringList($database['tables'] ?? null);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function parseOptionalVersion(array $data, string $snake, string $camel): ?string
    {
        $raw = $data[$snake] ?? $data[$camel] ?? null;
        if (!is_string($raw)) {
            return null;
        }
        $v = trim($raw);

        return $v !== '' ? $v : null;
    }

    /**
     * @param mixed $load
     */
    private static function parseLoadFlag(mixed $load, string $key, bool $default): bool
    {
        if (!is_array($load) || !array_key_exists($key, $load)) {
            return $default;
        }

        return (bool) $load[$key];
    }

}
