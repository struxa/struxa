<?php

declare(strict_types=1);

namespace StruxaAdmin;

use App\Filesystem\SafeDirectoryRemoval;
use App\Plugin\PluginManifestParser;
use App\Theme\ThemeManifest;
use App\Dist\StruxaDistCatalogWriter;
use App\Dist\ZipExtension;

/**
 * Builds ZIPs from GitHub and regenerates struxa-dist/repo.json + publish.json.
 */
final class CatalogPublisher
{
    public function __construct(
        private readonly CatalogSettings $settings,
        private readonly CatalogSubmissionRepository $submissions,
        private readonly GitHubRepoClient $github,
    ) {
    }

    /**
     * @return array{ok: true}|array{ok: false, error: string}
     */
    public function approveAndPublish(CatalogSubmission $submission): array
    {
        $parsed = $this->github->parseRepoUrl($submission->gitRepoUrl, $submission->gitBranch);
        if (!$parsed['ok']) {
            return ['ok' => false, 'error' => $parsed['error']];
        }

        $work = sys_get_temp_dir() . '/struxa-publish-' . bin2hex(random_bytes(6));
        if (!@mkdir($work, 0700, true)) {
            return ['ok' => false, 'error' => 'Could not create temporary directory.'];
        }

        try {
            $dl = $this->github->downloadZipballTo($parsed['owner'], $parsed['repo'], $parsed['branch'], $work);
            if (!$dl['ok']) {
                return ['ok' => false, 'error' => $dl['error']];
            }

            $packageRoot = $this->locatePackageRoot($dl['package_root'], $submission->kind, $submission->slug);
            if ($packageRoot === null) {
                return ['ok' => false, 'error' => 'Could not locate package root with a valid manifest in the archive.'];
            }

            $zipErr = $this->buildDistZip($packageRoot, $submission->slug, $submission->kind);
            if ($zipErr !== null) {
                return ['ok' => false, 'error' => $zipErr];
            }

            $this->updatePublishAllowlist($submission);
            $regen = $this->regenerateCatalog();
            if (!$regen['ok']) {
                return ['ok' => false, 'error' => $regen['error']];
            }

            return ['ok' => true];
        } finally {
            SafeDirectoryRemoval::removeIfInside($work, dirname($work));
        }
    }

    /**
     * @return array{ok: true}|array{ok: false, error: string}
     */
    public function regenerateCatalog(): array
    {
        $distRoot = $this->settings->distRoot();
        $zipsDir = $distRoot . '/zips';
        if (!is_dir($zipsDir)) {
            @mkdir($zipsDir, 0755, true);
        }

        $synced = $this->syncBundledPackagesFromCore($zipsDir);

        $baseUrl = $this->settings->zipBaseUrl();
        $screenshotBase = $this->settings->screenshotPublicBaseUrl();

        $themes = [];
        $plugins = [];
        foreach ($this->submissions->listApproved() as $sub) {
            $entry = $this->entryForApprovedSubmission($sub, $zipsDir, $baseUrl, $screenshotBase);
            if ($entry === null) {
                continue;
            }
            if ($sub->kind === SubmissionKind::THEME) {
                $themes = $this->upsertEntry($themes, $entry);
            } else {
                $plugins = $this->upsertEntry($plugins, $entry);
            }
        }

        $this->syncPublishJsonFromApproved();

        $themes = $this->upsertBundledStruxaVisionTheme($themes, $zipsDir, $baseUrl, $screenshotBase);
        $plugins = $this->upsertBundledStruxaAdminPlugin($plugins, $zipsDir, $baseUrl, $screenshotBase);

        usort($themes, static fn (array $a, array $b): int => strcmp($a['slug'], $b['slug']));
        usort($plugins, static fn (array $a, array $b): int => strcmp($a['slug'], $b['slug']));

        $written = (new StruxaDistCatalogWriter())->write($distRoot, $themes, $plugins);
        if (!$written['ok']) {
            return ['ok' => false, 'error' => $written['error'] ?? 'Failed to write catalog.'];
        }

        return ['ok' => true, 'synced_bundled' => $synced];
    }

    /**
     * Rebuild themes/struxa-theme.zip from disk and write repo.json (one step, no SSH).
     * Preserves existing plugin rows in repo.json.
     *
     * @return array{ok: true, version: string, dist_root: string}|array{ok: false, error: string}
     */
    public function publishBundledStruxaThemeToCatalog(): array
    {
        $slug = 'struxa-theme';
        $dir = $this->settings->projectRoot() . '/themes/' . $slug;
        if (ThemeManifest::tryLoadRelaxedPath($dir) === null) {
            return ['ok' => false, 'error' => 'themes/struxa-theme is missing or invalid (theme.json, views/, assets/).'];
        }

        $distRoot = $this->settings->distRoot();
        $zipsDir = $distRoot . '/zips';
        if (!is_dir($zipsDir) && !@mkdir($zipsDir, 0755, true)) {
            return ['ok' => false, 'error' => 'Could not create zips directory under ' . $distRoot];
        }

        $zipErr = $this->buildDistZip($dir, $slug, SubmissionKind::THEME);
        if ($zipErr !== null) {
            return ['ok' => false, 'error' => $zipErr];
        }

        $loaded = $this->loadExistingCatalogFromDisk($distRoot, $slug);
        if ($loaded['sharded_plugins']) {
            return [
                'ok' => false,
                'error' => 'Catalog uses sharded plugins. Use “Regenerate repo.json from approved” instead.',
            ];
        }

        $baseUrl = $this->settings->zipBaseUrl();
        $screenshotBase = $this->settings->screenshotPublicBaseUrl();
        $themes = $this->upsertBundledStruxaVisionTheme($loaded['themes'], $zipsDir, $baseUrl, $screenshotBase);

        usort($themes, static fn (array $a, array $b): int => strcmp((string) ($a['slug'] ?? ''), (string) ($b['slug'] ?? '')));
        usort($loaded['plugins'], static fn (array $a, array $b): int => strcmp((string) ($a['slug'] ?? ''), (string) ($b['slug'] ?? '')));

        $written = (new StruxaDistCatalogWriter())->write($distRoot, $themes, $loaded['plugins']);
        if (!$written['ok']) {
            return ['ok' => false, 'error' => $written['error'] ?? 'Failed to write catalog.'];
        }

        $version = '';
        foreach ($themes as $row) {
            if (($row['slug'] ?? '') === $slug) {
                $version = trim((string) ($row['version'] ?? ''));
                break;
            }
        }

        return ['ok' => true, 'version' => $version, 'dist_root' => $distRoot];
    }

    /**
     * Rebuild plugins/struxa-admin.zip from disk and upsert repo.json (no GitHub).
     * Preserves existing theme rows and other plugin rows.
     *
     * @return array{ok: true, version: string, dist_root: string}|array{ok: false, error: string}
     */
    public function publishBundledStruxaAdminToCatalog(): array
    {
        $slug = 'struxa-admin';
        $dir = $this->settings->projectRoot() . '/plugins/' . $slug;
        $parser = new PluginManifestParser();
        $jsonPath = $dir . '/plugin.json';
        if (!is_file($jsonPath)) {
            return ['ok' => false, 'error' => 'plugins/struxa-admin is missing or has no plugin.json.'];
        }
        $parsed = $parser->parseFile($jsonPath, $slug);
        if (!$parsed['ok']) {
            return ['ok' => false, 'error' => 'Invalid plugin.json for struxa-admin.'];
        }

        $distRoot = $this->settings->distRoot();
        $zipsDir = $distRoot . '/zips';
        if (!is_dir($zipsDir) && !@mkdir($zipsDir, 0755, true)) {
            return ['ok' => false, 'error' => 'Could not create zips directory under ' . $distRoot];
        }

        $zipErr = $this->buildDistZip($dir, $slug, SubmissionKind::PLUGIN);
        if ($zipErr !== null) {
            return ['ok' => false, 'error' => $zipErr];
        }

        $loaded = $this->loadExistingCatalogFromDisk($distRoot, '');
        if ($loaded['sharded_plugins']) {
            return [
                'ok' => false,
                'error' => 'Catalog uses sharded plugins. Use “Regenerate repo.json from approved” after upgrading catalog admin code.',
            ];
        }

        $baseUrl = $this->settings->zipBaseUrl();
        $screenshotBase = $this->settings->screenshotPublicBaseUrl();
        $plugins = $this->upsertBundledStruxaAdminPlugin($loaded['plugins'], $zipsDir, $baseUrl, $screenshotBase);

        usort($loaded['themes'], static fn (array $a, array $b): int => strcmp((string) ($a['slug'] ?? ''), (string) ($b['slug'] ?? '')));
        usort($plugins, static fn (array $a, array $b): int => strcmp((string) ($a['slug'] ?? ''), (string) ($b['slug'] ?? '')));

        $written = (new StruxaDistCatalogWriter())->write($distRoot, $loaded['themes'], $plugins);
        if (!$written['ok']) {
            return ['ok' => false, 'error' => $written['error'] ?? 'Failed to write catalog.'];
        }

        $this->ensureBundledAdminInPublishJson();

        $version = trim((string) ($parsed['manifest']['version'] ?? ''));

        return ['ok' => true, 'version' => $version, 'dist_root' => $distRoot];
    }

    /**
     * @return array{themes: list<array<string, mixed>>, plugins: list<array<string, mixed>>, sharded_plugins: bool}
     */
    private function loadExistingCatalogFromDisk(string $distRoot, string $excludeThemeSlug): array
    {
        $themes = [];
        $plugins = [];
        $sharded = false;
        $repoPath = rtrim($distRoot, '/\\') . '/repo.json';
        if (!is_readable($repoPath)) {
            return ['themes' => $themes, 'plugins' => $plugins, 'sharded_plugins' => false];
        }

        try {
            /** @var mixed $catalog */
            $catalog = json_decode((string) file_get_contents($repoPath), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['themes' => $themes, 'plugins' => $plugins, 'sharded_plugins' => false];
        }

        if (!is_array($catalog)) {
            return ['themes' => $themes, 'plugins' => $plugins, 'sharded_plugins' => false];
        }

        if (isset($catalog['themes']) && is_array($catalog['themes'])) {
            foreach ($catalog['themes'] as $row) {
                if (is_array($row) && ($row['slug'] ?? '') !== $excludeThemeSlug) {
                    $themes[] = $row;
                }
            }
        }

        if (isset($catalog['plugins']) && is_array($catalog['plugins'])) {
            if (isset($catalog['plugins']['shards'])) {
                $sharded = true;
            } else {
                foreach ($catalog['plugins'] as $row) {
                    if (is_array($row)) {
                        $plugins[] = $row;
                    }
                }
            }
        }

        return ['themes' => $themes, 'plugins' => $plugins, 'sharded_plugins' => $sharded];
    }

    /**
     * When the CMS ships themes/ or plugins/ in the project root (e.g. struxa-theme after a core update),
     * refresh distribution ZIPs so repo.json reflects the bundled manifest version, not a stale zip.
     *
     * @return list<string> Human-readable lines for admin flash (slug + version).
     */
    private function syncBundledPackagesFromCore(string $zipsDir): array
    {
        $synced = [];
        $root = $this->settings->projectRoot();

        $synced = array_merge($synced, $this->syncBundledStruxaAdminFromCore($zipsDir, $root));
        $synced = array_merge($synced, $this->syncBundledStruxaThemeFromCore($zipsDir, $root));

        foreach ($this->submissions->listApproved() as $sub) {
            if ($sub->kind === SubmissionKind::THEME) {
                $dir = $root . '/themes/' . $sub->slug;
                $manifest = ThemeManifest::tryLoadRelaxedPath($dir);
                if ($manifest === null) {
                    continue;
                }
                $bundledVersion = trim($manifest->version);
                if ($bundledVersion === '') {
                    continue;
                }
                $zipPath = $zipsDir . '/' . $sub->slug . '.zip';
                $zipVersion = $this->manifestVersionFromZip($zipPath, SubmissionKind::THEME);
                if ($zipVersion !== null && version_compare($bundledVersion, $zipVersion, '<=')) {
                    continue;
                }
                $err = $this->buildDistZip($dir, $sub->slug, SubmissionKind::THEME);
                if ($err === null) {
                    $synced[] = $sub->slug . ' theme v' . $bundledVersion;
                }
            } else {
                $dir = $root . '/plugins/' . $sub->slug;
                $parser = new PluginManifestParser();
                $jsonPath = $dir . '/plugin.json';
                if (!is_file($jsonPath)) {
                    continue;
                }
                $parsed = $parser->parseFile($jsonPath, $sub->slug);
                if (!$parsed['ok']) {
                    continue;
                }
                $bundledVersion = trim($parsed['manifest']->version);
                if ($bundledVersion === '') {
                    continue;
                }
                $zipPath = $zipsDir . '/' . $sub->slug . '.zip';
                $zipVersion = $this->manifestVersionFromZip($zipPath, SubmissionKind::PLUGIN);
                if ($zipVersion !== null && version_compare($bundledVersion, $zipVersion, '<=')) {
                    continue;
                }
                $err = $this->buildDistZip($dir, $sub->slug, SubmissionKind::PLUGIN);
                if ($err === null) {
                    $synced[] = $sub->slug . ' plugin v' . $bundledVersion;
                }
            }
        }

        return $synced;
    }

    private function manifestVersionFromZip(string $zipPath, string $kind): ?string
    {
        if (!is_file($zipPath)) {
            return null;
        }
        $manifest = $this->readManifestFromZip($zipPath, $kind);
        if ($manifest === null) {
            return null;
        }
        $version = trim((string) ($manifest['version'] ?? ''));

        return $version !== '' ? $version : null;
    }

    private function syncPublishJsonFromApproved(): void
    {
        $themes = [];
        $plugins = [];
        foreach ($this->submissions->listApproved() as $sub) {
            if ($sub->kind === SubmissionKind::THEME) {
                $themes[] = $sub->slug;
            } else {
                $plugins[] = $sub->slug;
            }
        }
        $publishPath = $this->settings->distRoot() . '/publish.json';
        $this->writePublishJson($publishPath, [
            'themes' => array_values(array_unique($themes)),
            'plugins' => array_values(array_unique($plugins)),
            'include_plugins' => $plugins !== [],
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function entryForApprovedSubmission(
        CatalogSubmission $sub,
        string $zipsDir,
        string $baseUrl,
        string $screenshotBase,
    ): ?array {
        $fromZip = $this->entryForZip($sub->slug, $sub->kind, $zipsDir, $baseUrl, $screenshotBase);
        if ($fromZip !== null) {
            return $fromZip;
        }

        return $this->entryFromSubmission($sub, $baseUrl, $screenshotBase);
    }

    private function updatePublishAllowlist(CatalogSubmission $submission): void
    {
        $publishPath = $this->settings->distRoot() . '/publish.json';
        $publish = $this->readPublishJson($publishPath);
        if ($submission->kind === SubmissionKind::THEME) {
            if (!in_array($submission->slug, $publish['themes'], true)) {
                $publish['themes'][] = $submission->slug;
            }
        } else {
            if (!in_array($submission->slug, $publish['plugins'], true)) {
                $publish['plugins'][] = $submission->slug;
            }
            $publish['include_plugins'] = true;
        }
        $this->writePublishJson($publishPath, $publish);
    }

    /**
     * @return array{themes: list<string>, plugins: list<string>, include_plugins: bool}
     */
    private function readPublishJson(string $path): array
    {
        $themes = ['default'];
        $plugins = [];
        $includePlugins = false;
        if (is_readable($path)) {
            try {
                /** @var mixed $data */
                $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
                if (is_array($data)) {
                    if (isset($data['themes']) && is_array($data['themes'])) {
                        $themes = [];
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
                    $includePlugins = !empty($data['include_plugins']);
                }
            } catch (\JsonException) {
            }
        }
        if ($themes === []) {
            $themes = ['default'];
        }

        return ['themes' => $themes, 'plugins' => $plugins, 'include_plugins' => $includePlugins];
    }

    /**
     * @param array{themes: list<string>, plugins: list<string>, include_plugins: bool} $publish
     */
    private function writePublishJson(string $path, array $publish): void
    {
        $out = json_encode([
            'themes' => array_values(array_unique($publish['themes'])),
            'plugins' => array_values(array_unique($publish['plugins'])),
            'include_plugins' => $publish['include_plugins'] || $publish['plugins'] !== [],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($out !== false) {
            file_put_contents($path, $out . "\n");
        }
    }

    private function buildDistZip(string $packageRoot, string $slug, string $kind): ?string
    {
        if (!ZipExtension::isAvailable()) {
            return ZipExtension::requiredError();
        }
        $zipsDir = $this->settings->distRoot() . '/zips';
        if (!is_dir($zipsDir) && !@mkdir($zipsDir, 0755, true)) {
            return 'Could not create zips directory.';
        }
        $dest = $zipsDir . '/' . $slug . '.zip';
        if (is_file($dest)) {
            @unlink($dest);
        }

        $zip = new ZipArchive();
        $openCode = $zip->open($dest, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($openCode !== true) {
            return 'Could not create distribution ZIP at ' . $dest . ' (ZipArchive::open code ' . (string) $openCode . '). Check directory permissions and open_basedir.';
        }

        $rootLen = strlen($packageRoot) + 1;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($packageRoot, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }
            $path = $file->getPathname();
            $rel = substr($path, $rootLen);
            if ($rel === false || str_contains($rel, '..')) {
                continue;
            }
            if ($kind === SubmissionKind::PLUGIN && (str_starts_with($rel, 'vendor/') || str_starts_with($rel, 'node_modules/'))) {
                continue;
            }
            if (str_contains($rel, '.git/')) {
                continue;
            }
            $zip->addFile($path, str_replace('\\', '/', $rel));
        }
        $zip->close();

        return is_file($dest) ? null : 'ZIP file was not created.';
    }

    private function locatePackageRoot(string $extractedTop, string $kind, string $expectedSlug): ?string
    {
        if ($kind === SubmissionKind::PLUGIN) {
            $parser = new PluginManifestParser();
            if (is_file($extractedTop . '/plugin.json')) {
                $r = $parser->parseFile($extractedTop . '/plugin.json', $expectedSlug);
                if ($r['ok']) {
                    return $extractedTop;
                }
            }
            foreach (scandir($extractedTop) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $sub = $extractedTop . '/' . $entry;
                if (is_dir($sub) && is_file($sub . '/plugin.json')) {
                    $r = $parser->parseFile($sub . '/plugin.json', $expectedSlug);
                    if ($r['ok']) {
                        return $sub;
                    }
                }
            }

            return null;
        }

        if (ThemeManifest::tryLoadRelaxedPath($extractedTop) !== null) {
            return $extractedTop;
        }
        foreach (scandir($extractedTop) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $sub = $extractedTop . '/' . $entry;
            if (is_dir($sub) && ThemeManifest::tryLoadRelaxedPath($sub) !== null) {
                return $sub;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function entryForZip(string $slug, string $kind, string $zipsDir, string $baseUrl, string $screenshotBase): ?array
    {
        $zipPath = $zipsDir . '/' . $slug . '.zip';
        if (!is_file($zipPath)) {
            return null;
        }
        $manifest = $this->readManifestFromZip($zipPath, $kind);
        if ($manifest === null) {
            $sub = $this->findApprovedBySlug($slug, $kind);

            return $sub !== null ? $this->entryFromSubmission($sub, $baseUrl, $screenshotBase) : null;
        }

        return $this->catalogEntryFromManifest($slug, $manifest, $kind, $baseUrl, null, $screenshotBase);
    }

    /**
     * @return array<string, mixed>
     */
    private function entryFromSubmission(CatalogSubmission $sub, string $baseUrl, string $screenshotBase): array
    {
        $screenshotUrl = $this->screenshotUrl($sub, $screenshotBase);

        return $this->catalogEntryFromManifest(
            $sub->slug,
            $sub->manifest,
            $sub->kind,
            $baseUrl,
            $sub->gitRepoUrl,
            $screenshotBase,
            $screenshotUrl
        );
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array<string, mixed>
     */
    private function catalogEntryFromManifest(
        string $slug,
        array $manifest,
        string $kind,
        string $baseUrl,
        ?string $repositoryUrl,
        string $screenshotBase,
        ?string $screenshotUrlOverride = null,
    ): array {
        $entry = [
            'slug' => $slug,
            'name' => trim((string) ($manifest['name'] ?? $slug)),
            'version' => trim((string) ($manifest['version'] ?? '1.0.0')),
            'description' => trim((string) ($manifest['description'] ?? '')),
            'author' => trim((string) ($manifest['author'] ?? '')),
            'download_url' => $this->settings->trackedDownloadUrl($kind, $slug),
        ];
        $req = $manifest['requires_cms_version'] ?? $manifest['min_cms_version'] ?? null;
        if (is_string($req) && trim($req) !== '') {
            $entry['requires_cms_version'] = trim($req);
        }
        $repo = $repositoryUrl ?? ($manifest['repository_url'] ?? null);
        if (is_string($repo) && $repo !== '') {
            $entry['repository_url'] = self::normalizeThemeRepositoryUrl($repo, $slug);
        }
        $shot = $screenshotUrlOverride;
        if ($shot === null && $screenshotBase !== '') {
            $sub = $this->findApprovedBySlug($slug, $kind);
            if ($sub !== null) {
                $shot = $this->screenshotUrl($sub, $screenshotBase);
            }
        }
        if ($shot !== null && $shot !== '') {
            $entry['screenshot_url'] = $shot;
        }

        return $entry;
    }

    /**
     * Struxa Vision is developed in the CMS monorepo; the standalone struxa-theme GitHub repo is not kept in sync.
     */
    private static function normalizeThemeRepositoryUrl(string $url, string $slug): string
    {
        $url = trim($url);
        if (preg_match('#github\.com/struxa/struxa-theme#i', $url) === 1 && $slug === 'struxa-theme') {
            return 'https://github.com/struxa/struxa';
        }

        return $url;
    }

    private function screenshotUrl(CatalogSubmission $sub, string $screenshotBase): ?string
    {
        if ($sub->screenshotPath === null || $sub->screenshotPath === '' || $screenshotBase === '') {
            return null;
        }

        return $screenshotBase . '/struxa-catalog/screenshot/' . rawurlencode(basename($sub->screenshotPath));
    }

    private function findApprovedBySlug(string $slug, string $kind): ?CatalogSubmission
    {
        foreach ($this->submissions->listApproved() as $sub) {
            if ($sub->slug === $slug && $sub->kind === $kind) {
                return $sub;
            }
        }

        return null;
    }

    /**
     * Core bundled Struxa Vision always wins over stale submission metadata in repo.json.
     *
     * @param list<array<string, mixed>> $themes
     *
     * @return list<array<string, mixed>>
     */
    private function upsertBundledStruxaVisionTheme(
        array $themes,
        string $zipsDir,
        string $baseUrl,
        string $screenshotBase,
    ): array {
        $slug = 'struxa-theme';
        $dir = $this->settings->projectRoot() . '/themes/' . $slug;
        if (!is_dir($dir) || ThemeManifest::tryLoadRelaxedPath($dir) === null) {
            return $themes;
        }

        $entry = $this->entryForZip($slug, SubmissionKind::THEME, $zipsDir, $baseUrl, $screenshotBase);
        if ($entry === null) {
            $jsonPath = $dir . '/theme.json';
            if (!is_readable($jsonPath)) {
                return $themes;
            }
            try {
                /** @var mixed $data */
                $data = json_decode((string) file_get_contents($jsonPath), true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return $themes;
            }
            if (!is_array($data)) {
                return $themes;
            }
            $entry = $this->catalogEntryFromManifest(
                $slug,
                $data,
                SubmissionKind::THEME,
                $baseUrl,
                isset($data['repository_url']) && is_string($data['repository_url']) ? $data['repository_url'] : null,
                $screenshotBase,
            );
        }

        return $this->upsertEntry($themes, $entry);
    }

    /**
     * Bundled catalog admin on disk always wins over stale submission metadata in repo.json.
     *
     * @param list<array<string, mixed>> $plugins
     *
     * @return list<array<string, mixed>>
     */
    private function upsertBundledStruxaAdminPlugin(
        array $plugins,
        string $zipsDir,
        string $baseUrl,
        string $screenshotBase,
    ): array {
        $slug = 'struxa-admin';
        $dir = $this->settings->projectRoot() . '/plugins/' . $slug;
        $jsonPath = $dir . '/plugin.json';
        if (!is_file($jsonPath)) {
            return $plugins;
        }

        $parser = new PluginManifestParser();
        $parsed = $parser->parseFile($jsonPath, $slug);
        if (!$parsed['ok']) {
            return $plugins;
        }
        $m = $parsed['manifest'];
        $entry = $this->catalogEntryFromManifest(
            $slug,
            [
                'name' => $m->name,
                'slug' => $m->slug,
                'version' => $m->version,
                'description' => $m->description,
                'author' => $m->author,
                'requires_cms_version' => $m->requiresCmsVersion,
            ],
            SubmissionKind::PLUGIN,
            $baseUrl,
            'https://github.com/struxa/struxa',
            $screenshotBase,
        );

        return $this->upsertEntry($plugins, $entry);
    }

    /**
     * @return list<string>
     */
    private function syncBundledStruxaAdminFromCore(string $zipsDir, string $root): array
    {
        $slug = 'struxa-admin';
        $dir = $root . '/plugins/' . $slug;
        $jsonPath = $dir . '/plugin.json';
        if (!is_file($jsonPath)) {
            return [];
        }
        $parser = new PluginManifestParser();
        $parsed = $parser->parseFile($jsonPath, $slug);
        if (!$parsed['ok']) {
            return [];
        }
        $bundledVersion = trim($parsed['manifest']->version);
        if ($bundledVersion === '') {
            return [];
        }
        $zipPath = $zipsDir . '/' . $slug . '.zip';
        $zipVersion = $this->manifestVersionFromZip($zipPath, SubmissionKind::PLUGIN);
        if ($zipVersion !== null && version_compare($bundledVersion, $zipVersion, '<=')) {
            return [];
        }
        if ($this->buildDistZip($dir, $slug, SubmissionKind::PLUGIN) !== null) {
            return [];
        }

        return ['struxa-admin plugin v' . $bundledVersion];
    }

    /**
     * @return list<string>
     */
    private function syncBundledStruxaThemeFromCore(string $zipsDir, string $root): array
    {
        $slug = 'struxa-theme';
        $manifest = ThemeManifest::tryLoadRelaxedPath($root . '/themes/' . $slug);
        if ($manifest === null) {
            return [];
        }
        $bundledVersion = trim($manifest->version);
        if ($bundledVersion === '') {
            return [];
        }
        $zipPath = $zipsDir . '/' . $slug . '.zip';
        $zipVersion = $this->manifestVersionFromZip($zipPath, SubmissionKind::THEME);
        if ($zipVersion !== null && version_compare($bundledVersion, $zipVersion, '<=')) {
            return [];
        }
        $dir = $root . '/themes/' . $slug;
        if ($this->buildDistZip($dir, $slug, SubmissionKind::THEME) !== null) {
            return [];
        }

        return ['struxa-theme v' . $bundledVersion];
    }

    private function ensureBundledAdminInPublishJson(): void
    {
        $publishPath = $this->settings->distRoot() . '/publish.json';
        $publish = $this->readPublishJson($publishPath);
        if (!in_array('struxa-admin', $publish['plugins'], true)) {
            $publish['plugins'][] = 'struxa-admin';
        }
        $publish['include_plugins'] = true;
        if (!in_array('struxa-theme', $publish['themes'], true)) {
            $publish['themes'][] = 'struxa-theme';
        }
        $this->writePublishJson($publishPath, $publish);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readManifestFromZip(string $zipPath, string $kind): ?array
    {
        if (!ZipExtension::isAvailable()) {
            return null;
        }
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return null;
        }
        $file = $kind === SubmissionKind::PLUGIN ? 'plugin.json' : 'theme.json';
        $json = $zip->getFromName($file);
        if ($json === false) {
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $name = $zip->getNameIndex($i);
                if (is_string($name) && str_ends_with($name, '/' . $file)) {
                    $json = $zip->getFromIndex($i);
                    break;
                }
            }
        }
        $zip->close();
        if ($json === false || $json === '') {
            return null;
        }
        try {
            /** @var mixed $data */
            $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($data) ? $data : null;
    }

    /**
     * @param list<array<string, mixed>> $list
     * @param array<string, mixed> $entry
     * @return list<array<string, mixed>>
     */
    private function upsertEntry(array $list, array $entry): array
    {
        $slug = (string) ($entry['slug'] ?? '');
        $out = [];
        $replaced = false;
        foreach ($list as $row) {
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
}
