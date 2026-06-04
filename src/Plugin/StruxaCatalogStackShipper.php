<?php

declare(strict_types=1);

namespace App\Plugin;

use App\Dist\PackageZipBuilder;
use App\Dist\StruxaDistCatalogWriter;
use PDO;
use StruxaAdmin\CatalogPublisher;
use StruxaAdmin\CatalogSettings;
use StruxaAdmin\CatalogSubmissionRepository;
use StruxaAdmin\GitHubRepoClient;
use StruxaAdmin\SubmissionKind;

/**
 * One-click: upgrade struxa-admin, publish its ZIP + repo.json row, regenerate catalog, run migrations.
 */
final class StruxaCatalogStackShipper
{
    private const SLUG = 'struxa-admin';

    private const GITHUB_OWNER = 'struxa';

    private const GITHUB_REPO = 'struxa-admin';

    public function __construct(
        private readonly string $projectRoot,
        private readonly PDO $pdo,
        private readonly PluginScanner $scanner,
        private readonly PluginRepository $plugins,
        private readonly PluginMigrationRunner $migrations,
    ) {
    }

    /**
     * @return array{
     *   ok: true,
     *   version: string,
     *   messages: list<string>,
     *   reload_recommended: bool
     * }|array{ok: false, error: string}
     */
    public function ship(): array
    {
        if (!class_exists(CatalogSettings::class)) {
            return ['ok' => false, 'error' => 'Struxa Catalog Admin is not loaded. Install plugins/struxa-admin first.'];
        }

        $messages = [];
        $pluginsRoot = $this->projectRoot . '/plugins';
        $installer = new PluginRemoteInstaller($pluginsRoot, $this->scanner, $this->projectRoot);

        $ghErr = $installer->updateFromGithubRepository(self::SLUG, self::GITHUB_OWNER, self::GITHUB_REPO, 'main');
        if ($ghErr !== null) {
            $catErr = $this->tryCatalogZipUpdate($installer);
            if ($catErr !== null) {
                return [
                    'ok' => false,
                    'error' => 'Could not upgrade struxa-admin from GitHub: ' . $ghErr
                        . ($catErr !== $ghErr ? ' Catalog ZIP: ' . $catErr : ''),
                ];
            }
            $messages[] = 'Replaced struxa-admin from the catalog download URL.';
        } else {
            $messages[] = 'Downloaded struxa-admin from GitHub (' . self::GITHUB_OWNER . '/' . self::GITHUB_REPO . ').';
        }

        $this->scanner->clearDiscoverCache();
        $discovered = $this->scanner->findBySlug(self::SLUG);
        if ($discovered === null) {
            return ['ok' => false, 'error' => 'struxa-admin folder is missing after upgrade.'];
        }

        $version = trim($discovered->manifest->version);
        $settings = new CatalogSettings($this->pdo, $this->projectRoot);

        $regen = $this->regenerateCatalog($settings);
        if (!$regen['ok']) {
            return ['ok' => false, 'error' => $regen['error'] ?? 'Catalog regenerate failed.'];
        }
        if (isset($regen['message']) && is_string($regen['message'])) {
            $messages[] = $regen['message'];
        }

        // Publish last: regenerate can overwrite struxa-admin with an old approved submission row.
        $pub = $this->publishAdminToCatalog($settings, $discovered);
        if (!$pub['ok']) {
            return ['ok' => false, 'error' => $pub['error'] ?? 'Failed to publish struxa-admin to the catalog.'];
        }
        $messages[] = $pub['message'] ?? 'Published struxa-admin to repo.json and struxa-admin.zip.';

        try {
            $this->migrations->runPending(self::SLUG, $discovered->rootPath . '/migrations');
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Catalog published but migrations failed: ' . $e->getMessage()];
        }
        $messages[] = 'Plugin migrations applied.';

        $this->plugins->upsertFromManifest($discovered->manifest);
        $this->plugins->setActive(self::SLUG, true);
        $messages[] = 'struxa-admin is active (v' . $version . ').';

        return [
            'ok' => true,
            'version' => $version,
            'messages' => $messages,
            'reload_recommended' => true,
        ];
    }

    /**
     * @return array{ok: true, message: string}|array{ok: false, error: string}
     */
    private function publishAdminToCatalog(CatalogSettings $settings, DiscoveredPlugin $discovered): array
    {
        if (class_exists(CatalogPublisher::class)) {
            $publisher = new CatalogPublisher(
                $settings,
                new CatalogSubmissionRepository($this->pdo),
                new GitHubRepoClient($settings->githubToken()),
            );
            if (method_exists($publisher, 'publishBundledStruxaAdminToCatalog')) {
                $result = $publisher->publishBundledStruxaAdminToCatalog();
                if (!$result['ok']) {
                    return ['ok' => false, 'error' => $result['error'] ?? 'Publish failed.'];
                }
                $ver = trim((string) ($result['version'] ?? ''));

                return [
                    'ok' => true,
                    'message' => $ver !== ''
                        ? 'Published struxa-admin v' . $ver . ' to repo.json and struxa-admin.zip.'
                        : 'Published struxa-admin to repo.json and struxa-admin.zip.',
                ];
            }
        }

        return $this->publishAdminToCatalogCore($settings, $discovered->manifest);
    }

    /**
     * @return array{ok: true, message: string}|array{ok: false, error: string}
     */
    private function publishAdminToCatalogCore(CatalogSettings $settings, PluginManifest $manifest): array
    {
        $slug = self::SLUG;
        $dir = $this->projectRoot . '/plugins/' . $slug;
        $distRoot = $settings->distRoot();
        $zipsDir = rtrim($distRoot, '/\\') . '/zips';

        $zipErr = (new PackageZipBuilder())->buildPluginZip($dir, $slug, $zipsDir);
        if ($zipErr !== null) {
            return ['ok' => false, 'error' => $zipErr];
        }

        $loaded = $this->loadCatalogPlugins($distRoot);
        if ($loaded['sharded']) {
            return [
                'ok' => false,
                'error' => 'Catalog uses sharded plugins. Regenerate from Catalog settings after upgrading the plugin code.',
            ];
        }

        $entry = [
            'slug' => $slug,
            'name' => $manifest->name,
            'version' => $manifest->version,
            'description' => $manifest->description,
            'author' => $manifest->author,
            'download_url' => $settings->trackedDownloadUrl(SubmissionKind::PLUGIN, $slug),
        ];
        $reqCms = trim((string) ($manifest->requiresCmsVersion ?? ''));
        if ($reqCms !== '') {
            $entry['requires_cms_version'] = $reqCms;
        }
        $entry['repository_url'] = 'https://github.com/' . self::GITHUB_OWNER . '/' . self::GITHUB_REPO;

        $plugins = $this->upsertCatalogEntry($loaded['plugins'], $entry);
        $themes = $loaded['themes'];
        usort($themes, static fn (array $a, array $b): int => strcmp((string) ($a['slug'] ?? ''), (string) ($b['slug'] ?? '')));
        usort($plugins, static fn (array $a, array $b): int => strcmp((string) ($a['slug'] ?? ''), (string) ($b['slug'] ?? '')));

        $written = (new StruxaDistCatalogWriter())->write($distRoot, $themes, $plugins);
        if (!$written['ok']) {
            return ['ok' => false, 'error' => $written['error'] ?? 'Failed to write repo.json.'];
        }

        $this->ensurePublishJsonListsAdmin($distRoot);

        return [
            'ok' => true,
            'message' => 'Published struxa-admin v' . $manifest->version . ' to repo.json and struxa-admin.zip.',
        ];
    }

    /**
     * @return array{ok: true, message?: string}|array{ok: false, error: string}
     */
    private function regenerateCatalog(CatalogSettings $settings): array
    {
        if (!class_exists(CatalogPublisher::class)) {
            return ['ok' => true];
        }

        $publisher = new CatalogPublisher(
            $settings,
            new CatalogSubmissionRepository($this->pdo),
            new GitHubRepoClient($settings->githubToken()),
        );
        $regen = $publisher->regenerateCatalog();
        if (!$regen['ok']) {
            return ['ok' => false, 'error' => $regen['error'] ?? 'Regenerate failed.'];
        }

        $msg = 'Regenerated repo.json from approved submissions.';
        $synced = $regen['synced_bundled'] ?? [];
        if (is_array($synced) && $synced !== []) {
            $msg .= ' Bundled sync: ' . implode(', ', $synced) . '.';
        }

        return ['ok' => true, 'message' => $msg];
    }

    private function tryCatalogZipUpdate(PluginRemoteInstaller $installer): ?string
    {
        if (!class_exists(PluginCatalogLoader::class)) {
            return 'Plugin catalog loader is not available on this server (deploy the latest CMS).';
        }

        $loader = new PluginCatalogLoader($this->projectRoot);
        $loaded = $loader->load();
        if (!$loaded['ok']) {
            return 'Catalog index could not be loaded.';
        }

        return $installer->updateFromCatalogSlug(self::SLUG, $loaded['entries']);
    }

    private function ensurePublishJsonListsAdmin(string $distRoot): void
    {
        $path = rtrim($distRoot, '/\\') . '/publish.json';
        $themes = ['struxa-theme'];
        $plugins = [self::SLUG];
        $includePlugins = true;
        if (is_readable($path)) {
            try {
                /** @var mixed $data */
                $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
                if (is_array($data)) {
                    if (isset($data['themes']) && is_array($data['themes'])) {
                        foreach ($data['themes'] as $t) {
                            if (is_string($t) && $t !== '') {
                                $themes[] = strtolower(trim($t));
                            }
                        }
                    }
                    if (isset($data['plugins']) && is_array($data['plugins'])) {
                        foreach ($data['plugins'] as $p) {
                            if (is_string($p) && $p !== '') {
                                $plugins[] = strtolower(trim($p));
                            }
                        }
                    }
                    $includePlugins = !empty($data['include_plugins']) || $plugins !== [];
                }
            } catch (\JsonException) {
            }
        }
        $themes = array_values(array_unique($themes));
        $plugins = array_values(array_unique($plugins));
        $out = json_encode([
            'themes' => $themes,
            'plugins' => $plugins,
            'include_plugins' => $includePlugins,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($out !== false) {
            file_put_contents($path, $out . "\n");
        }
    }

    /**
     * @return array{themes: list<array<string, mixed>>, plugins: list<array<string, mixed>>, sharded: bool}
     */
    private function loadCatalogPlugins(string $distRoot): array
    {
        $themes = [];
        $plugins = [];
        $repoPath = rtrim($distRoot, '/\\') . '/repo.json';
        if (!is_readable($repoPath)) {
            return ['themes' => $themes, 'plugins' => $plugins, 'sharded' => false];
        }

        try {
            /** @var mixed $catalog */
            $catalog = json_decode((string) file_get_contents($repoPath), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['themes' => $themes, 'plugins' => $plugins, 'sharded' => false];
        }

        if (!is_array($catalog)) {
            return ['themes' => $themes, 'plugins' => $plugins, 'sharded' => false];
        }

        if (isset($catalog['themes']) && is_array($catalog['themes'])) {
            foreach ($catalog['themes'] as $row) {
                if (is_array($row)) {
                    $themes[] = $row;
                }
            }
        }

        if (isset($catalog['plugins']) && is_array($catalog['plugins'])) {
            if (isset($catalog['plugins']['shards'])) {
                return ['themes' => $themes, 'plugins' => $plugins, 'sharded' => true];
            }
            foreach ($catalog['plugins'] as $row) {
                if (is_array($row)) {
                    $plugins[] = $row;
                }
            }
        }

        return ['themes' => $themes, 'plugins' => $plugins, 'sharded' => false];
    }

    /**
     * @param list<array<string, mixed>> $list
     * @param array<string, mixed> $entry
     *
     * @return list<array<string, mixed>>
     */
    private function upsertCatalogEntry(array $list, array $entry): array
    {
        $slug = (string) ($entry['slug'] ?? '');
        $out = [];
        $replaced = false;
        foreach ($list as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (($row['slug'] ?? '') === $slug) {
                $out[] = $entry;
                $replaced = true;
            } else {
                $out[] = $row;
            }
        }
        if (!$replaced) {
            $out[] = $entry;
        }

        return $out;
    }

    /**
     * struxa-dist path without constructing CatalogSettings (avoids PDO in route closures).
     */
    public static function resolveDistRoot(string $projectRoot): string
    {
        $settingKeys = ['struxa_admin_dist_root'];
        if (class_exists(CatalogSettings::class)) {
            $settingKeys[] = CatalogSettings::KEY_DIST_ROOT;
        }
        foreach (array_unique($settingKeys) as $key) {
            $custom = trim((string) (\App\Settings::get($key, '') ?? ''));
            if ($custom !== '') {
                return rtrim($custom, '/\\');
            }
        }

        foreach ([
            $projectRoot . '/public/struxa-dist',
            $projectRoot . '/struxa-dist',
        ] as $path) {
            if (is_dir($path)) {
                return $path;
            }
        }

        return rtrim($projectRoot, '/\\') . '/struxa-dist';
    }

    public static function diskVersion(string $projectRoot): ?string
    {
        $path = $projectRoot . '/plugins/' . self::SLUG . '/plugin.json';
        if (!is_file($path)) {
            return null;
        }
        $parser = new PluginManifestParser();
        $parsed = $parser->parseFile($path, self::SLUG);

        if (!$parsed['ok']) {
            return null;
        }
        $manifest = $parsed['manifest'];
        if (!$manifest instanceof PluginManifest) {
            return null;
        }

        return trim($manifest->version) !== '' ? trim($manifest->version) : null;
    }

    public static function repoVersion(string $distRoot): ?string
    {
        $repoPath = rtrim($distRoot, '/\\') . '/repo.json';
        if (!is_readable($repoPath)) {
            return null;
        }
        try {
            /** @var mixed $catalog */
            $catalog = json_decode((string) file_get_contents($repoPath), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($catalog) || !isset($catalog['plugins']) || !is_array($catalog['plugins'])) {
            return null;
        }
        if (isset($catalog['plugins']['shards'])) {
            return null;
        }
        foreach ($catalog['plugins'] as $row) {
            if (is_array($row) && ($row['slug'] ?? '') === self::SLUG) {
                $v = trim((string) ($row['version'] ?? ''));

                return $v !== '' ? $v : null;
            }
        }

        return null;
    }
}
