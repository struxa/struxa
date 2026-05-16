<?php

declare(strict_types=1);

namespace App\Dev;

use App\Plugin\DiscoveredPlugin;
use App\Plugin\PluginRepository;
use App\Plugin\PluginScanner;
use PDO;
use PDOException;

/**
 * Read-only checks for plugin Composer layout vs root and autoload health.
 */
final class PluginDependencyHealthCheck
{
    /** @var array<string, true> */
    private array $rootPackageNames = [];

    public function __construct(
        private readonly string $projectRoot,
    ) {
        $this->loadRootComposerPackageNames();
    }

    /**
     * @return list<PluginDependencyHealthIssue>
     */
    public function run(bool $activeOnly = false, bool $warnPackagesNotInRoot = true): array
    {
        $pluginsRoot = $this->projectRoot . DIRECTORY_SEPARATOR . 'plugins';
        if (
            is_file($pluginsRoot . DIRECTORY_SEPARATOR . '.gitkeep')
            && !is_file($pluginsRoot . DIRECTORY_SEPARATOR . '.struxa-bundle-plugins')
        ) {
            return [];
        }

        $issues = [];
        $scanner = new PluginScanner($this->projectRoot);
        $plugins = $scanner->discover();

        if ($activeOnly) {
            $pdo = $this->tryPdo();
            if ($pdo === null) {
                $issues[] = new PluginDependencyHealthIssue(
                    'error',
                    'active_only_no_database',
                    '--active-only requires a working MySQL connection (.env DB_*). Run without --active-only to scan all discovered plugins.',
                    '(global)',
                );

                return $issues;
            }
            $active = (new PluginRepository($pdo))->activeSlugs();
            $activeSet = array_fill_keys($active, true);
            $plugins = array_values(array_filter($plugins, static fn (DiscoveredPlugin $p): bool => isset($activeSet[$p->manifest->slug])));
        }

        foreach ($plugins as $plugin) {
            $slug = $plugin->manifest->slug;
            $rootPath = $plugin->rootPath;
            $composerPath = $rootPath . DIRECTORY_SEPARATOR . 'composer.json';
            $vendorAutoload = $rootPath . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

            $hasComposerJson = is_file($composerPath);
            $hasVendorDir = is_dir($rootPath . DIRECTORY_SEPARATOR . 'vendor');
            $hasComposerLock = is_file($rootPath . DIRECTORY_SEPARATOR . 'composer.lock');

            if (!$hasComposerJson && $hasVendorDir && !$hasComposerLock) {
                $issues[] = new PluginDependencyHealthIssue(
                    'warning',
                    'orphan_vendor_directory',
                    'Directory vendor/ exists without composer.json (or lock). Prefer removing stray vendor or adding composer.json.',
                    $slug,
                );
            }

            if (!$hasComposerJson && is_file($rootPath . DIRECTORY_SEPARATOR . 'composer.lock')) {
                $issues[] = new PluginDependencyHealthIssue(
                    'error',
                    'plugin_composer_lock_without_json',
                    'composer.lock exists without composer.json. Restore composer.json or remove the lock file.',
                    $slug,
                );
            }

            if ($hasComposerJson) {
                $data = $this->readJson($composerPath);
                if ($data === null) {
                    $issues[] = new PluginDependencyHealthIssue(
                        'error',
                        'plugin_composer_invalid_json',
                        'composer.json is not valid JSON.',
                        $slug,
                    );
                    continue;
                }
                $requires = $this->mergeRequires($data);
                $thirdParty = array_filter(array_keys($requires), $this->isThirdPartyPackage(...));

                if ($thirdParty !== [] && !is_file($vendorAutoload)) {
                    $issues[] = new PluginDependencyHealthIssue(
                        'error',
                        'plugin_vendor_autoload_missing',
                        'composer.json declares third-party packages but vendor/autoload.php is missing. Run: composer plugin-deps (or composer install in this plugin directory).',
                        $slug,
                    );
                }

                if ($warnPackagesNotInRoot) {
                    foreach ($thirdParty as $pkg) {
                        if (!isset($this->rootPackageNames[$pkg])) {
                            $issues[] = new PluginDependencyHealthIssue(
                                'warning',
                                'package_not_in_root_composer',
                                "Package \"{$pkg}\" is required in the plugin composer.json but not in the root composer.json require section. Prefer hoisting shared libraries to the root unless you intentionally isolate this tree (see docs/plugins-dependencies.md).",
                                $slug,
                            );
                        }
                    }
                }
            }

            $main = $plugin->manifest->mainClass;
            if ($main !== null && $main !== '') {
                $resolved = $this->resolveMainClass($plugin, $hasComposerJson, is_file($vendorAutoload));
                if (!$resolved) {
                    $issues[] = new PluginDependencyHealthIssue(
                        'error',
                        'main_class_unresolved',
                        "Class \"{$main}\" is not loadable after bootstrapping root autoload, plugin vendor (if present), and plugin.json PSR-4.",
                        $slug,
                    );
                }
            }
        }

        return $issues;
    }

    private function resolveMainClass(DiscoveredPlugin $plugin, bool $hasComposerJson, bool $vendorAutoloadExists): bool
    {
        $rootAutoload = $this->projectRoot . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
        if (is_file($rootAutoload)) {
            require_once $rootAutoload;
        }

        if ($hasComposerJson && $vendorAutoloadExists) {
            $va = $plugin->rootPath . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
            if (is_file($va)) {
                require_once $va;
            }
        }

        $this->registerManifestPsr4($plugin);

        return class_exists($plugin->manifest->mainClass ?? '', true);
    }

    private function registerManifestPsr4(DiscoveredPlugin $plugin): void
    {
        $psr4 = $plugin->manifest->autoloadPsr4;
        if ($psr4 === null) {
            return;
        }
        $base = $plugin->rootPath . '/';
        foreach ($psr4 as $prefix => $relative) {
            $dir = $base . trim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative), DIRECTORY_SEPARATOR);
            if (!is_dir($dir)) {
                continue;
            }
            $prefix = rtrim($prefix, '\\') . '\\';
            spl_autoload_register(static function (string $class) use ($prefix, $dir): void {
                if (!str_starts_with($class, $prefix)) {
                    return;
                }
                $rel = substr($class, strlen($prefix));
                $file = $dir . '/' . str_replace('\\', DIRECTORY_SEPARATOR, $rel) . '.php';
                if (is_file($file)) {
                    require_once $file;
                }
            });
        }
    }

    private function tryPdo(): ?PDO
    {
        $root = $this->projectRoot;
        if (is_readable($root . '/.env')) {
            \Dotenv\Dotenv::createImmutable($root)->safeLoad();
        }

        $dbHost = $_ENV['DB_HOST'] ?? '127.0.0.1';
        $dbPort = $_ENV['DB_PORT'] ?? '3306';
        $dbName = $_ENV['DB_NAME'] ?? 'studio';
        $dbUser = $_ENV['DB_USER'] ?? 'studio';
        $dbPass = $_ENV['DB_PASS'] ?? 'studio';
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $dbHost, $dbPort, $dbName);

        try {
            return new PDO($dsn, $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
        } catch (PDOException) {
            return null;
        }
    }

    private function loadRootComposerPackageNames(): void
    {
        $path = $this->projectRoot . DIRECTORY_SEPARATOR . 'composer.json';
        if (!is_file($path)) {
            return;
        }
        $data = $this->readJson($path);
        if ($data === null) {
            return;
        }
        $req = $data['require'] ?? [];
        if (!is_array($req)) {
            return;
        }
        foreach ($req as $name => $_) {
            if (!is_string($name)) {
                continue;
            }
            if ($this->isThirdPartyPackage($name)) {
                $this->rootPackageNames[$name] = true;
            }
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readJson(string $path): ?array
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function mergeRequires(array $data): array
    {
        $req = $data['require'] ?? [];
        $dev = $data['require-dev'] ?? [];

        return array_merge(
            is_array($req) ? $req : [],
            is_array($dev) ? $dev : [],
        );
    }

    private function isThirdPartyPackage(string $name): bool
    {
        $name = strtolower($name);
        if ($name === 'php') {
            return false;
        }
        if (str_starts_with($name, 'ext-')) {
            return false;
        }
        if (str_starts_with($name, 'lib-')) {
            return false;
        }

        return true;
    }
}
