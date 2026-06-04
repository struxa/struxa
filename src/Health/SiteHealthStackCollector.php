<?php

declare(strict_types=1);

namespace App\Health;

use App\CmsVersion;
use App\Plugin\PluginRepository;
use App\Plugin\PluginScanner;
use App\Settings;
use App\Theme\ThemeCatalogLoader;
use App\Theme\ThemeManager;
use App\Theme\ThemeUpdateChecker;
use App\Update\CmsUpdateChecker;
use App\Cache\CacheManager;
use App\Plugin\StruxaCatalogAdminRouteRegistrar;
use PDO;
use Slim\App;
use Throwable;

/**
 * Installed stack versions (CMS, theme, plugins) and sync/update hints for Site health.
 *
 * @phpstan-type StackRow array{
 *   kind: string,
 *   name: string,
 *   slug: string,
 *   active: bool,
 *   installed_version: string,
 *   disk_version: string|null,
 *   latest_version: string|null,
 *   sync_status: string,
 *   sync_label: string,
 *   detail: string,
 *   action_route: string|null,
 *   action_label: string|null
 * }
 */
final class SiteHealthStackCollector
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $projectRoot,
    ) {
    }

    /**
     * @return array{
     *   cms: StackRow,
     *   theme: StackRow|null,
     *   plugins: list<StackRow>
     * }
     */
    public function collect(): array
    {
        return [
            'cms' => $this->cmsRow(),
            'theme' => $this->themeRow(),
            'plugins' => $this->pluginRows(),
        ];
    }

    /**
     * Drop action_route when the named route is not registered (inactive plugin, failed boot).
     *
     * @param array{cms: StackRow, theme: StackRow|null, plugins: list<StackRow>} $stack
     * @return array{cms: StackRow, theme: StackRow|null, plugins: list<StackRow>}
     */
    public static function withoutMissingActionRoutes(App $app, array $stack): array
    {
        $sanitize = static function (array $row) use ($app): array {
            $route = $row['action_route'] ?? null;
            if (!is_string($route) || $route === '') {
                return $row;
            }
            if (!StruxaCatalogAdminRouteRegistrar::namedRouteExists($app, $route)) {
                $row['action_route'] = null;
            }

            return $row;
        };

        $stack['cms'] = $sanitize($stack['cms']);
        if ($stack['theme'] !== null) {
            $stack['theme'] = $sanitize($stack['theme']);
        }
        $stack['plugins'] = array_map($sanitize, $stack['plugins']);

        return $stack;
    }

    /**
     * @return StackRow
     */
    private function cmsRow(): array
    {
        $code = CmsVersion::CURRENT;
        $latest = null;
        $updateAvailable = false;
        $detail = 'Shipped in this codebase (' . $code . ').';
        $actionRoute = 'admin.updates.index';
        $actionLabel = 'CMS updates';

        try {
            $cache = (new CacheManager($this->projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache'))->internal();
            $checker = new CmsUpdateChecker($cache, $this->pdo);
            $status = $checker->checkForAdminUi();
            if ($status['ok']) {
                $latest = isset($status['latest_version']) ? trim($status['latest_version']) : null;
                if ($latest === '') {
                    $latest = null;
                }
                $updateAvailable = $status['update_available'];
                if ($updateAvailable && $latest !== null) {
                    $detail = 'Update available: Struxa ' . $latest . '.';
                } elseif ($latest !== null) {
                    $detail = 'Remote feed reports latest as ' . $latest . ' (matches or ahead of install).';
                }
            } elseif (isset($status['error'])) {
                $err = trim($status['error']);
                if ($err !== '') {
                    $detail = 'Could not check remote feed: ' . $err;
                }
            }
        } catch (Throwable $e) {
            $detail = 'Could not check remote feed: ' . $e->getMessage();
        }

        $syncStatus = $updateAvailable ? 'update_available' : 'ok';
        $syncLabel = $updateAvailable ? 'Update available' : 'Up to date';

        return [
            'kind' => 'cms',
            'name' => 'Struxa CMS',
            'slug' => 'struxa',
            'active' => true,
            'installed_version' => $code,
            'disk_version' => $code,
            'latest_version' => $latest,
            'sync_status' => $syncStatus,
            'sync_label' => $syncLabel,
            'detail' => $detail,
            'action_route' => $actionRoute,
            'action_label' => $actionLabel,
        ];
    }

    /**
     * @return StackRow|null
     */
    private function themeRow(): ?array
    {
        $themes = new ThemeManager($this->projectRoot);
        $activeSlug = Settings::get('active_theme', '');
        if ($activeSlug === null || trim($activeSlug) === '') {
            return null;
        }
        $activeSlug = trim($activeSlug);
        $manifest = $themes->findBySlug($activeSlug);
        if ($manifest === null) {
            return [
                'kind' => 'theme',
                'name' => $activeSlug,
                'slug' => $activeSlug,
                'active' => true,
                'installed_version' => '—',
                'disk_version' => null,
                'latest_version' => null,
                'sync_status' => 'missing_on_disk',
                'sync_label' => 'Missing on disk',
                'detail' => 'Active theme is set in settings but the theme folder was not found.',
                'action_route' => 'admin.themes.index',
                'action_label' => 'Themes',
            ];
        }

        $installed = $manifest->version;
        $latest = null;
        $updateAvailable = false;
        $detail = 'Active storefront theme.';

        try {
            $catalogLoader = new ThemeCatalogLoader($this->projectRoot);
            $entry = null;
            foreach ($catalogLoader->loadOrEmpty() as $catalogEntry) {
                if ($catalogEntry->slug === $activeSlug) {
                    $entry = $catalogEntry;
                    break;
                }
            }
            $cache = (new CacheManager($this->projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache'))->internal();
            $checker = new ThemeUpdateChecker($cache, $catalogLoader);
            $status = $checker->statusFor($manifest, $entry);
            $latest = $status['latest_version'] !== null ? trim($status['latest_version']) : null;
            if ($latest === '') {
                $latest = null;
            }
            $updateAvailable = $status['update_available'];
            if ($updateAvailable && $latest !== null) {
                $detail = 'Catalog/GitHub has version ' . $latest . '; installed ' . $installed . '.';
            } elseif ($status['error'] !== null && $status['error'] !== '') {
                $detail = $status['error'];
            }
        } catch (Throwable $e) {
            $detail = 'Could not check theme catalog: ' . $e->getMessage();
        }

        $syncStatus = $updateAvailable ? 'update_available' : 'ok';
        $syncLabel = $updateAvailable ? 'Update available' : 'Up to date';

        return [
            'kind' => 'theme',
            'name' => $manifest->name,
            'slug' => $activeSlug,
            'active' => true,
            'installed_version' => $installed,
            'disk_version' => $installed,
            'latest_version' => $latest,
            'sync_status' => $syncStatus,
            'sync_label' => $syncLabel,
            'detail' => $detail,
            'action_route' => 'admin.themes.index',
            'action_label' => 'Themes',
        ];
    }

    /**
     * @return list<StackRow>
     */
    private function pluginRows(): array
    {
        $scanner = new PluginScanner($this->projectRoot);
        $repo = new PluginRepository($this->pdo);
        $diskBySlug = [];
        foreach ($scanner->discover() as $discovered) {
            $diskBySlug[$discovered->manifest->slug] = $discovered->manifest;
        }

        $rows = [];
        $seen = [];

        foreach ($repo->allOrdered() as $record) {
            $seen[$record->slug] = true;
            $rows[] = $this->pluginRow($record->slug, $record->name, $record->version, $record->isActive, $diskBySlug[$record->slug] ?? null);
        }

        foreach ($diskBySlug as $slug => $manifest) {
            if (isset($seen[$slug])) {
                continue;
            }
            $rows[] = $this->pluginRow($slug, $manifest->name, '—', false, $manifest);
        }

        usort($rows, static function (array $a, array $b): int {
            if ($a['active'] !== $b['active']) {
                return $a['active'] ? -1 : 1;
            }

            return strcasecmp($a['name'], $b['name']);
        });

        return $rows;
    }

    /**
     * @return StackRow
     */
    private function pluginRow(string $slug, string $name, string $dbVersion, bool $active, ?\App\Plugin\PluginManifest $diskManifest): array
    {
        $diskVersion = $diskManifest?->version;
        $detail = $active ? 'Active plugin.' : 'Installed on disk; not active.';
        $syncStatus = 'ok';
        $syncLabel = 'In sync';
        $actionRoute = 'admin.extensions.plugins.index';
        $actionLabel = 'Plugins';

        if ($diskManifest === null) {
            return [
                'kind' => 'plugin',
                'name' => $name,
                'slug' => $slug,
                'active' => $active,
                'installed_version' => $dbVersion,
                'disk_version' => null,
                'latest_version' => null,
                'sync_status' => 'missing_on_disk',
                'sync_label' => 'Missing on disk',
                'detail' => 'Registered in the database but plugin folder or plugin.json is missing.',
                'action_route' => $actionRoute,
                'action_label' => $actionLabel,
            ];
        }

        if ($dbVersion === '—' || $dbVersion === '') {
            $syncStatus = 'not_registered';
            $syncLabel = 'Not in database';
            $detail = 'Present on disk but not registered — open Plugins to scan, or activate once.';
            $installed = $diskVersion ?? '—';
        } elseif ($diskVersion !== null && $this->versionsDiffer($dbVersion, $diskVersion)) {
            $syncStatus = 'outdated_metadata';
            $syncLabel = 'DB version stale';
            $detail = 'Database shows ' . $dbVersion . ' but plugin.json on disk is ' . $diskVersion
                . '. Re-open Plugins or deactivate/reactivate to refresh metadata.';
            $installed = $dbVersion;
        } else {
            $installed = $dbVersion !== '—' ? $dbVersion : ($diskVersion ?? '—');
        }

        if ($active && $diskVersion !== null) {
            $contentSync = $this->pluginContentSyncDetail($slug);
            if ($contentSync !== null) {
                $syncStatus = 'content_sync_pending';
                $syncLabel = 'Content sync pending';
                $detail = $contentSync;
                $actionRoute = $this->pluginContentSyncRoute($slug);
                $actionLabel = $actionRoute !== null ? 'Open plugin' : $actionLabel;
            }
        }

        if ($active && $syncStatus === 'ok') {
            $pending = $this->pendingPluginMigrations($slug);
            if ($pending !== []) {
                $syncStatus = 'migrations_pending';
                $syncLabel = 'Migrations pending';
                $detail = 'Pending migration(s): ' . implode(', ', array_slice($pending, 0, 3))
                    . (count($pending) > 3 ? '…' : '') . '. Deactivate and reactivate the plugin to apply.';
            }
        }

        return [
            'kind' => 'plugin',
            'name' => $name,
            'slug' => $slug,
            'active' => $active,
            'installed_version' => $installed,
            'disk_version' => $diskVersion,
            'latest_version' => null,
            'sync_status' => $syncStatus,
            'sync_label' => $syncLabel,
            'detail' => $detail,
            'action_route' => $actionRoute,
            'action_label' => $actionLabel,
        ];
    }

    private function versionsDiffer(string $a, string $b): bool
    {
        $a = trim($a);
        $b = trim($b);
        if ($a === $b) {
            return false;
        }
        if ($a === '' || $b === '' || $a === '—' || $b === '—') {
            return true;
        }

        return version_compare($a, $b, '!=');
    }

    /**
     * @return list<string>
     */
    private function pendingPluginMigrations(string $slug): array
    {
        $dir = $this->projectRoot . '/plugins/' . $slug . '/migrations';
        if (!is_dir($dir)) {
            return [];
        }

        try {
            $stmt = $this->pdo->prepare('SELECT name FROM cms_plugin_migrations WHERE plugin_slug = ?');
            $stmt->execute([$slug]);
            $applied = [];
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $name) {
                $applied[(string) $name] = true;
            }
            $pending = [];
            foreach (glob($dir . '/*.sql') ?: [] as $path) {
                $name = basename($path);
                if (!isset($applied[$name])) {
                    $pending[] = $name;
                }
            }

            return $pending;
        } catch (Throwable) {
            return [];
        }
    }

    private function pluginContentSyncDetail(string $slug): ?string
    {
        if ($slug !== 'knowledge-base-plugin' || !class_exists(\KnowledgeBasePlugin\KnowledgeBaseSettings::class)) {
            return null;
        }

        try {
            $current = \KnowledgeBasePlugin\KnowledgeBaseSettings::wikiContentVersion($this->pdo);
            $target = \KnowledgeBasePlugin\KnowledgeBaseSettings::WIKI_CONTENT_VERSION;
            if ($current >= $target) {
                return null;
            }

            return 'Knowledge Base bundled articles are at wiki content version ' . $current
                . '; catalog ships version ' . $target . '. Visit Knowledge Base in admin to sync articles.';
        } catch (Throwable) {
            return null;
        }
    }

    private function pluginContentSyncRoute(string $slug): ?string
    {
        return match ($slug) {
            'knowledge-base-plugin' => 'plugin.knowledge_base_plugin.index',
            default => null,
        };
    }
}
