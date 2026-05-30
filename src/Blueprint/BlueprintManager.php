<?php

declare(strict_types=1);

namespace App\Blueprint;

use App\Access\WorkflowService;
use App\Content\ContentEntryRepository;
use App\Content\ContentEntryValueRepository;
use App\Content\ContentFieldRepository;
use App\Content\ContentSlugger;
use App\Content\ContentTypeRepository;
use App\Media\MediaRepository;
use App\Media\MediaStorage;
use App\Menu\MenuItemRepository;
use App\Menu\MenuRepository;
use App\Page\PageRepository;
use App\Seo\RedirectRepository;
use App\Seo\SeoFormParser;
use App\Page\PageTagParser;
use App\Section\PageSectionRepository;
use App\Section\SectionSchemaValidator;
use App\Settings\SettingsRepository;
use App\Settings;
use App\SiteProfile\SiteProfileRepository;
use App\Taxonomy\ContentEntryTaxonomyRepository;
use App\Taxonomy\TaxonomyRepository;
use App\Taxonomy\TaxonomyTermRepository;
use App\Theme\ThemeManager;
use PDO;
use PDOException;

final class BlueprintManager
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $projectRoot,
        private readonly ContentTypeRepository $types,
        private readonly ContentFieldRepository $fields,
        private readonly TaxonomyRepository $tax,
        private readonly TaxonomyTermRepository $terms,
        private readonly MenuRepository $menus,
        private readonly MenuItemRepository $menuItems,
        private readonly SettingsRepository $settingsRepo,
        private readonly SiteProfileRepository $profile,
        private readonly ThemeManager $themes,
        private readonly ContentEntryRepository $entries,
        private readonly ContentEntryValueRepository $entryValues,
        private readonly PageRepository $pages,
        private readonly PageSectionRepository $pageSections,
        private readonly SectionSchemaValidator $sectionSchema,
        private readonly ContentEntryTaxonomyRepository $entryTaxonomy,
        private readonly StructureCollector $collector,
    ) {
    }

    public function blueprintsDirectory(): string
    {
        return $this->projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'blueprints';
    }

    /**
     * @return list<array{filename: string, label: string, bytes: int}>
     */
    public function listStoredBlueprints(): array
    {
        $dir = $this->blueprintsDirectory();
        if (!is_dir($dir)) {
            return [];
        }
        $out = [];
        foreach (scandir($dir) ?: [] as $f) {
            if (!str_ends_with(strtolower($f), '.json')) {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $f;
            if (!is_file($path)) {
                continue;
            }
            $label = $f;
            $raw = @file_get_contents($path);
            if ($raw !== false) {
                try {
                    /** @var mixed $j */
                    $j = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                    if (is_array($j) && isset($j['label']) && is_string($j['label']) && $j['label'] !== '') {
                        $label = $j['label'];
                    }
                } catch (\JsonException) {
                }
            }
            $out[] = [
                'filename' => $f,
                'label' => $label,
                'bytes' => (int) @filesize($path),
            ];
        }
        usort($out, static fn (array $a, array $b): int => strcasecmp($a['filename'], $b['filename']));

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function exportCurrentAsBlueprint(string $label, bool $includeEntries, int $maxEntriesPerType = 50): array
    {
        return $this->collector->collectFull($label, $includeEntries, $maxEntriesPerType);
    }

    public function saveBlueprintFile(string $basename, array $payload): void
    {
        if (!preg_match('/^[a-z0-9][a-z0-9\-]{0,60}$/', $basename)) {
            throw new \InvalidArgumentException('Invalid blueprint filename.');
        }
        $dir = $this->blueprintsDirectory();
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create blueprints directory.');
        }
        $path = $dir . DIRECTORY_SEPARATOR . $basename . '.json';
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($path, $json) === false) {
            throw new \RuntimeException('Failed to write blueprint file.');
        }
    }

    /**
     * @return array{errors: list<string>, warnings: list<string>, applied: list<string>}
     */
    public function importBlueprint(array $payload, BlueprintImportOptions $opt): array
    {
        $validator = new BlueprintSchemaValidator();
        $errors = $validator->validate($payload);
        if ($errors !== []) {
            return ['errors' => $errors, 'warnings' => [], 'applied' => []];
        }

        return $this->applyBlueprintPayload($payload, $opt);
    }

    /**
     * Import from partial structure export (cms_structure_export_version).
     *
     * @param list<string> $scopes content_types|menus|settings|entries|meta
     * @return array{errors: list<string>, warnings: list<string>, applied: list<string>}
     */
    public function applyPartialExport(array $payload, array $scopes, BlueprintImportOptions $opt): array
    {
        $scopes = array_flip($scopes);
        $errors = [];
        if (!isset($payload['cms_structure_export_version']) || $payload['cms_structure_export_version'] !== '1.0') {
            $errors[] = 'cms_structure_export_version must be "1.0".';
        }
        if ($errors !== []) {
            return ['errors' => $errors, 'warnings' => [], 'applied' => []];
        }

        $fake = [
            'cms_blueprint_version' => BlueprintSchemaValidator::VERSION,
            'label' => 'import',
            'content_types' => isset($scopes['content_types']) && isset($payload['content_types']) && is_array($payload['content_types'])
                ? $payload['content_types'] : [],
            'menus' => isset($scopes['menus']) && isset($payload['menus']) && is_array($payload['menus'])
                ? $payload['menus'] : [],
            'settings' => isset($scopes['settings']) && isset($payload['settings']) && is_array($payload['settings'])
                ? $payload['settings'] : [],
            'active_theme_slug' => $payload['active_theme_slug'] ?? null,
            'content_entries' => ($opt->importContentEntries && isset($scopes['entries']) && isset($payload['content_entries']) && is_array($payload['content_entries']))
                ? $payload['content_entries'] : [],
            'media_seed' => ($opt->importContentEntries && isset($scopes['entries']) && isset($payload['media_seed']) && is_array($payload['media_seed']))
                ? $payload['media_seed'] : [],
        ];
        if (array_key_exists('public_homepage_page_slug', $payload)) {
            $hs = $payload['public_homepage_page_slug'];
            $fake['public_homepage_page_slug'] = is_string($hs) ? $hs : '';
        }

        $errors = (new BlueprintSchemaValidator())->validate($fake);
        if ($errors !== []) {
            return ['errors' => $errors, 'warnings' => [], 'applied' => []];
        }

        $optEntries = new BlueprintImportOptions(
            $opt->dryRun,
            $opt->merge,
            isset($scopes['meta']) && $opt->applyThemeFromBlueprint,
            $opt->importContentEntries && isset($scopes['entries'])
        );

        return $this->applyBlueprintPayload($fake, $optEntries);
    }

    /**
     * @return array{errors: list<string>, warnings: list<string>, applied: list<string>}
     */
    private function applyBlueprintPayload(array $payload, BlueprintImportOptions $opt): array
    {
        $warnings = [];
        $applied = [];
        $run = !$opt->dryRun;

        $exec = function (callable $fn) use ($run): void {
            if ($run) {
                $fn();
            }
        };

        $homepageSlugKeyPresent = array_key_exists('public_homepage_page_slug', $payload);

        try {
            if ($run) {
                $this->pdo->beginTransaction();
            }

            if (isset($payload['settings']) && is_array($payload['settings'])) {
                $n = 0;
                foreach ($payload['settings'] as $k => $v) {
                    if (!is_string($k) || $k === '' || !is_string($v)) {
                        continue;
                    }
                    if ($k === 'public_homepage_page_id' && $homepageSlugKeyPresent) {
                        continue;
                    }
                    ++$n;
                    $exec(function () use ($k, $v): void {
                        $this->settingsRepo->upsert($k, $v, true);
                    });
                }
                if ($n > 0) {
                    $applied[] = $run ? 'settings merged (' . $n . ' keys)' : 'would merge ' . $n . ' setting keys';
                }
            }

            if ($opt->applyThemeFromBlueprint && isset($payload['active_theme_slug']) && is_string($payload['active_theme_slug'])) {
                $slug = trim($payload['active_theme_slug']);
                if ($slug !== '' && $this->themes->findBySlug($slug) !== null) {
                    $exec(function () use ($slug): void {
                        $this->settingsRepo->upsert('active_theme', $slug, true);
                    });
                    if ($run) {
                        $applied[] = 'active theme set to ' . $slug;
                    } else {
                        $applied[] = 'would set active theme to ' . $slug;
                    }
                } elseif ($slug !== '') {
                    $warnings[] = 'Theme "' . $slug . '" is not installed; skipped.';
                }
            }

            foreach ($payload['content_types'] as $t) {
                if (!is_array($t)) {
                    continue;
                }
                $slug = (string) ($t['slug'] ?? '');
                $existing = $this->types->findBySlug($slug);
                if ($existing !== null && !$opt->merge) {
                    $warnings[] = 'Skipped existing content type: ' . $slug;

                    continue;
                }
                $typeId = 0;
                if ($existing === null) {
                    if ($run) {
                        $typeId = $this->types->insert(
                            (string) ($t['name'] ?? $slug),
                            $slug,
                            isset($t['icon']) && is_string($t['icon']) ? $t['icon'] : null,
                            isset($t['description']) && is_string($t['description']) ? $t['description'] : null,
                            !empty($t['has_public_route']),
                            !empty($t['supports_seo']),
                            !empty($t['supports_featured_image'])
                        );
                        $applied[] = 'created content type ' . $slug;
                    }
                } else {
                    $typeId = $existing->id;
                    $warnings[] = 'Merged into existing content type: ' . $slug;
                }

                if (!$run || $typeId < 1) {
                    continue;
                }

                $this->importFields($typeId, $t['fields'] ?? [], $opt->merge, $applied, $warnings);
                $this->importTaxonomies($typeId, $t['taxonomies'] ?? [], $opt->merge, $applied, $warnings, $run);
            }

            if (isset($payload['pages']) && is_array($payload['pages'])) {
                $this->importPages($payload['pages'], $opt->merge, $applied, $warnings, $run);
            }

            $this->applyPublicHomepageFromBlueprintSlug($payload, $homepageSlugKeyPresent, $exec, $applied, $warnings, $run);

            if (isset($payload['redirects']) && is_array($payload['redirects'])) {
                $this->importRedirects($payload['redirects'], $applied, $warnings, $run);
            }

            if (isset($payload['menus']) && is_array($payload['menus'])) {
                foreach ($payload['menus'] as $m) {
                    if (!is_array($m)) {
                        continue;
                    }
                    $loc = (string) ($m['location'] ?? '');
                    if (!in_array($loc, ['header', 'footer'], true)) {
                        $warnings[] = 'Skipped menu with invalid location.';

                        continue;
                    }
                    $menu = $this->menus->findByLocation($loc);
                    $menuId = 0;
                    if ($menu === null) {
                        if ($run) {
                            $name = is_string($m['name'] ?? null) && $m['name'] !== '' ? $m['name'] : ucfirst($loc);
                            $menuId = $this->menus->insert($name, $loc);
                            $applied[] = 'created menu ' . $loc;
                        }
                    } else {
                        $menuId = $menu->id;
                    }
                    if (!$run || $menuId < 1) {
                        continue;
                    }
                    $sortBase = $this->maxMenuSort($menuId);
                    $i = 0;
                    foreach ($m['items'] ?? [] as $it) {
                        if (!is_array($it)) {
                            continue;
                        }
                        ++$i;
                        $label = (string) ($it['label'] ?? '');
                        if ($label === '') {
                            continue;
                        }
                        $url = (string) ($it['url'] ?? '');
                        $pageId = null;
                        $ps = $it['page_slug'] ?? null;
                        if (is_string($ps) && $ps !== '') {
                            $pg = $this->pages->findBySlug($ps);
                            $pageId = $pg?->id;
                            if ($pageId === null) {
                                $warnings[] = 'Menu item "' . $label . '": page slug "' . $ps . '" not found.';
                            }
                        }
                        $exec(function () use ($menuId, $label, $url, $pageId, $sortBase, $i, $it): void {
                            $this->menuItems->insert(
                                $menuId,
                                $label,
                                $url,
                                $pageId,
                                $sortBase + $i,
                                is_string($it['target'] ?? null) ? $it['target'] : '_self',
                                is_string($it['css_class'] ?? null) ? $it['css_class'] : ''
                            );
                        });
                    }
                    if ($i > 0 && $run) {
                        $applied[] = 'appended ' . $i . ' items to menu ' . $loc;
                    }
                }
            }

            $mediaSlugToId = [];
            if (isset($payload['media_seed']) && is_array($payload['media_seed'])) {
                $mediaSlugToId = $this->importMediaSeed($payload['media_seed'], $run, $applied, $warnings);
            }

            if ($opt->importContentEntries && isset($payload['content_entries']) && is_array($payload['content_entries'])) {
                $this->importEntries($payload['content_entries'], $opt->merge, $applied, $warnings, $run, $mediaSlugToId);
            }

            if ($run) {
                if (!$homepageSlugKeyPresent) {
                    $this->clearStalePublicHomepagePageId($warnings);
                }
                $this->pdo->commit();
                Settings::reload($this->pdo);
            }
        } catch (PDOException|\Throwable $e) {
            if ($run && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            return [
                'errors' => ['Import failed: ' . $e->getMessage()],
                'warnings' => $warnings,
                'applied' => [],
            ];
        }

        if ($opt->dryRun) {
            return [
                'errors' => [],
                'warnings' => array_merge($warnings, ['Dry run only — no changes were saved.']),
                'applied' => array_merge(
                    $applied,
                    ['Validated structure; click “Apply to database” to write.']
                ),
            ];
        }

        return ['errors' => [], 'warnings' => $warnings, 'applied' => $applied];
    }

    private function maxMenuSort(int $menuId): int
    {
        $stmt = $this->pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM cms_menu_items WHERE menu_id = ?');
        $stmt->execute([$menuId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param list<mixed> $fields
     * @param list<string> $applied
     * @param list<string> $warnings
     */
    private function importFields(int $typeId, array $fields, bool $merge, array &$applied, array &$warnings): void
    {
        foreach ($fields as $f) {
            if (!is_array($f)) {
                continue;
            }
            $key = (string) ($f['field_key'] ?? '');
            if ($key === '') {
                continue;
            }
            if ($this->fields->fieldKeyExists($typeId, $key, null)) {
                if ($merge) {
                    continue;
                }
                $warnings[] = 'Skipped duplicate field ' . $key . ' on type id ' . $typeId;

                continue;
            }
            $this->fields->insert(
                $typeId,
                (string) ($f['label'] ?? $key),
                $key,
                (string) ($f['field_type'] ?? 'text'),
                isset($f['placeholder']) && is_string($f['placeholder']) ? $f['placeholder'] : null,
                isset($f['help_text']) && is_string($f['help_text']) ? $f['help_text'] : null,
                !empty($f['is_required']),
                isset($f['default_value']) && is_string($f['default_value']) ? $f['default_value'] : null,
                isset($f['options_json']) && is_string($f['options_json']) ? $f['options_json'] : null,
                (int) ($f['sort_order'] ?? 0)
            );
            $applied[] = 'field ' . $key . ' on type ' . $typeId;
        }
    }

    /**
     * @param list<mixed> $taxonomies
     * @param list<string> $applied
     * @param list<string> $warnings
     */
    private function importTaxonomies(int $typeId, array $taxonomies, bool $merge, array &$applied, array &$warnings, bool $run): void
    {
        if (!$run) {
            return;
        }
        foreach ($taxonomies as $tx) {
            if (!is_array($tx)) {
                continue;
            }
            $slug = (string) ($tx['slug'] ?? '');
            if ($slug === '') {
                continue;
            }
            $existing = $this->tax->findByContentTypeAndSlug($typeId, $slug);
            if ($existing !== null) {
                $taxId = $existing->id;
                $warnings[] = 'Merged taxonomy ' . $slug;
            } else {
                $taxId = $this->tax->insert(
                    $typeId,
                    (string) ($tx['name'] ?? $slug),
                    $slug,
                    isset($tx['description']) && is_string($tx['description']) ? $tx['description'] : null,
                    in_array(($tx['taxonomy_type'] ?? 'custom'), ['category', 'tag', 'custom'], true)
                        ? (string) $tx['taxonomy_type'] : 'custom',
                    !empty($tx['is_hierarchical'])
                );
                $applied[] = 'taxonomy ' . $slug;
            }
            $this->importTerms($taxId, $tx['terms'] ?? [], $merge, $applied, $warnings);
        }
    }

    /**
     * @param list<mixed> $terms
     */
    private function importTerms(int $taxId, array $terms, bool $merge, array &$applied, array &$warnings): void
    {
        $slugToId = [];
        $existing = $this->terms->forTaxonomyOrdered($taxId);
        foreach ($existing as $e) {
            $slugToId[$e->slug] = $e->id;
        }
        $pending = $terms;
        $guard = 0;
        while ($pending !== [] && $guard < 500) {
            ++$guard;
            $next = [];
            $progress = false;
            foreach ($pending as $t) {
                if (!is_array($t)) {
                    continue;
                }
                $slug = (string) ($t['slug'] ?? '');
                if ($slug === '') {
                    continue;
                }
                if (isset($slugToId[$slug])) {
                    $progress = true;

                    continue;
                }
                $ps = $t['parent_slug'] ?? null;
                $parentId = null;
                if (is_string($ps) && $ps !== '') {
                    if (!isset($slugToId[$ps])) {
                        $next[] = $t;

                        continue;
                    }
                    $parentId = $slugToId[$ps];
                }
                $existingTerm = $this->terms->findByTaxonomyAndSlug($taxId, $slug);
                if ($merge && $existingTerm !== null) {
                    $slugToId[$slug] = $existingTerm->id;
                    $progress = true;

                    continue;
                }
                $termOg = (isset($t['og_image_id']) && is_numeric($t['og_image_id']) && (int) $t['og_image_id'] > 0) ? (int) $t['og_image_id'] : null;
                $termTw = (isset($t['twitter_image_id']) && is_numeric($t['twitter_image_id']) && (int) $t['twitter_image_id'] > 0) ? (int) $t['twitter_image_id'] : null;
                $termOg = $this->blueprintImageMediaIdOrNull($termOg);
                $termTw = $this->blueprintImageMediaIdOrNull($termTw);
                $id = $this->terms->insert(
                    $taxId,
                    (string) ($t['name'] ?? $slug),
                    $slug,
                    isset($t['description']) && is_string($t['description']) ? $t['description'] : null,
                    $parentId,
                    (int) ($t['sort_order'] ?? 0),
                    isset($t['seo_title']) && is_string($t['seo_title']) && trim($t['seo_title']) !== '' ? trim($t['seo_title']) : null,
                    isset($t['seo_description']) && is_string($t['seo_description']) && trim($t['seo_description']) !== '' ? trim($t['seo_description']) : null,
                    isset($t['canonical_url']) && is_string($t['canonical_url']) && trim($t['canonical_url']) !== '' ? trim($t['canonical_url']) : null,
                    !empty($t['seo_noindex']),
                    isset($t['og_title']) && is_string($t['og_title']) && trim($t['og_title']) !== '' ? trim($t['og_title']) : null,
                    isset($t['og_description']) && is_string($t['og_description']) && trim($t['og_description']) !== '' ? trim($t['og_description']) : null,
                    $termOg,
                    isset($t['twitter_title']) && is_string($t['twitter_title']) && trim($t['twitter_title']) !== '' ? trim($t['twitter_title']) : null,
                    isset($t['twitter_description']) && is_string($t['twitter_description']) && trim($t['twitter_description']) !== '' ? trim($t['twitter_description']) : null,
                    $termTw,
                    $this->blueprintSchemaJsonOrNull($t['schema_json'] ?? null, 'Taxonomy term "' . $slug . '" schema_json', $warnings)
                );
                $slugToId[$slug] = $id;
                $applied[] = 'term ' . $slug;
                $progress = true;
            }
            if (!$progress) {
                $warnings[] = 'Some taxonomy terms could not be imported (missing parents).';

                break;
            }
            $pending = $next;
        }
    }

    /**
     * Download remote images into public/uploads/blueprint-seed and insert cms_media rows.
     * Entries can set featured_image_media_slug to match each item's slug.
     *
     * @param list<mixed> $items
     * @return array<string, int> slug => media id
     */
    private function importMediaSeed(array $items, bool $run, array &$applied, array &$warnings): array
    {
        $map = [];
        if ($items === []) {
            return $map;
        }

        if (!$run) {
            foreach ($items as $it) {
                if (is_array($it) && (string) ($it['slug'] ?? '') !== '') {
                    $applied[] = 'would media_seed ' . (string) $it['slug'];
                }
            }

            return $map;
        }

        $dir = $this->projectRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'blueprint-seed';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            $warnings[] = 'Could not create public/uploads/blueprint-seed; media_seed skipped.';

            return $map;
        }

        $repo = new MediaRepository($this->pdo);
        $finfo = class_exists(\finfo::class) ? new \finfo(FILEINFO_MIME_TYPE) : null;

        foreach ($items as $it) {
            if (!is_array($it)) {
                continue;
            }
            $slug = trim((string) ($it['slug'] ?? ''));
            $url = trim((string) ($it['source_url'] ?? ''));
            if ($slug === '' || $url === '') {
                $warnings[] = 'Skipped media_seed row (missing slug or source_url).';

                continue;
            }
            if (!preg_match('#^https://#i', $url)) {
                $warnings[] = 'media_seed URLs must use https: ' . $slug;

                continue;
            }
            $host = parse_url($url, PHP_URL_HOST);
            if (!is_string($host) || strtolower($host) !== 'images.unsplash.com') {
                $warnings[] = 'media_seed host must be images.unsplash.com (got ' . (is_string($host) ? $host : '?') . '): ' . $slug;

                continue;
            }

            $ctx = stream_context_create([
                'http' => ['timeout' => 20, 'header' => "User-Agent: PulseCMS-Blueprint/1.0\r\n"],
                'ssl' => ['verify_peer' => true],
            ]);
            $binary = @file_get_contents($url, false, $ctx);
            if ($binary === false || $binary === '') {
                $warnings[] = 'Could not download media_seed image: ' . $slug;

                continue;
            }
            if (strlen($binary) > 6_000_000) {
                $warnings[] = 'media_seed image too large (>6MB): ' . $slug;

                continue;
            }

            $ext = '';
            if ($finfo !== null) {
                $mime = $finfo->buffer($binary);
                $ext = match ($mime) {
                    'image/jpeg' => 'jpg',
                    'image/png' => 'png',
                    'image/webp' => 'webp',
                    'image/gif' => 'gif',
                    default => '',
                };
            }
            if ($ext === '') {
                $warnings[] = 'media_seed file is not a supported image (jpeg/png/webp/gif): ' . $slug;

                continue;
            }

            $safeSlug = preg_replace('/[^a-z0-9\-]+/', '-', strtolower($slug)) ?? $slug;
            $safeSlug = trim($safeSlug, '-') ?: 'asset';
            $filename = $safeSlug . '.' . $ext;
            $webPath = MediaStorage::WEB_PREFIX . 'blueprint-seed/' . $filename;
            if (!MediaStorage::isSafeManagedWebPath($webPath)) {
                $warnings[] = 'media_seed path rejected for ' . $slug;

                continue;
            }

            $fullPath = $this->projectRoot . DIRECTORY_SEPARATOR . 'public' . str_replace('/', DIRECTORY_SEPARATOR, $webPath);
            if (file_put_contents($fullPath, $binary) === false) {
                $warnings[] = 'Could not write media_seed file: ' . $slug;

                continue;
            }

            $mimeType = match ($ext) {
                'jpg' => 'image/jpeg',
                'png' => 'image/png',
                'webp' => 'image/webp',
                'gif' => 'image/gif',
                default => 'application/octet-stream',
            };
            $id = $repo->insert(
                $filename,
                'Blueprint seed: ' . $slug,
                $mimeType,
                $ext,
                strlen($binary),
                $webPath,
                null,
                null,
                null
            );
            $map[$slug] = $id;
            $applied[] = 'media_seed ' . $slug;
        }

        return $map;
    }

    /**
     * @param list<mixed> $entries
     * @param list<string> $applied
     * @param list<string> $warnings
     * @param array<string, int> $mediaSlugToId from media_seed (slug => cms_media.id)
     */
    private function importEntries(array $entries, bool $merge, array &$applied, array &$warnings, bool $run, array $mediaSlugToId = []): void
    {
        if (!$run) {
            return;
        }
        foreach ($entries as $e) {
            if (!is_array($e)) {
                continue;
            }
            $typeSlug = (string) ($e['content_type_slug'] ?? '');
            $type = $this->types->findBySlug($typeSlug);
            if ($type === null) {
                $warnings[] = 'Entry skipped — unknown type ' . $typeSlug;

                continue;
            }
            $slug = (string) ($e['slug'] ?? '');
            if ($slug === '') {
                $slug = ContentSlugger::slugify((string) ($e['title'] ?? 'entry'));
            }
            if ($merge && $this->entries->slugExists($type->id, $slug, null)) {
                $warnings[] = 'Entry slug exists, skipped: ' . $slug;

                continue;
            }
            $slug = ContentSlugger::ensureUniqueEntry($this->entries, $type->id, $slug, null);
            $status = (string) ($e['status'] ?? 'draft');
            $pub = isset($e['published_at']) && is_string($e['published_at']) ? $e['published_at'] : null;
            $fid = isset($e['featured_image_id']) ? (int) $e['featured_image_id'] : null;
            $fid = $fid > 0 ? $fid : null;
            if ($fid === null && isset($e['featured_image_media_slug']) && is_string($e['featured_image_media_slug'])) {
                $ms = trim($e['featured_image_media_slug']);
                if ($ms !== '' && isset($mediaSlugToId[$ms])) {
                    $fid = $mediaSlugToId[$ms];
                }
            }
            $ogId = isset($e['og_image_id']) && is_numeric($e['og_image_id']) ? (int) $e['og_image_id'] : null;
            $twId = isset($e['twitter_image_id']) && is_numeric($e['twitter_image_id']) ? (int) $e['twitter_image_id'] : null;
            $ogId = $ogId !== null && $ogId > 0 ? $ogId : null;
            $twId = $twId !== null && $twId > 0 ? $twId : null;

            $fidBefore = $fid;
            $fid = $this->blueprintImageMediaIdOrNull($fid);
            if ($fidBefore !== null && $fid === null) {
                $warnings[] = 'Entry "' . $slug . '": featured_image_id not in media library (import from another site?); cleared.';
            }
            $ogBefore = $ogId;
            $ogId = $this->blueprintImageMediaIdOrNull($ogId);
            if ($ogBefore !== null && $ogId === null) {
                $warnings[] = 'Entry "' . $slug . '": og_image_id not in media library; cleared.';
            }
            $twBefore = $twId;
            $twId = $this->blueprintImageMediaIdOrNull($twId);
            if ($twBefore !== null && $twId === null) {
                $warnings[] = 'Entry "' . $slug . '": twitter_image_id not in media library; cleared.';
            }

            $schemaJ = $this->blueprintSchemaJsonOrNull($e['schema_json'] ?? null, 'Entry "' . $slug . '" schema_json', $warnings);
            $eid = $this->entries->insert(
                $type->id,
                (string) ($e['title'] ?? 'Untitled'),
                $slug,
                $status,
                $fid,
                isset($e['seo_title']) && is_string($e['seo_title']) ? $e['seo_title'] : null,
                isset($e['seo_description']) && is_string($e['seo_description']) ? $e['seo_description'] : null,
                isset($e['focus_keyphrase']) && is_string($e['focus_keyphrase']) && trim($e['focus_keyphrase']) !== '' ? trim($e['focus_keyphrase']) : null,
                isset($e['canonical_url']) && is_string($e['canonical_url']) && trim($e['canonical_url']) !== '' ? trim($e['canonical_url']) : null,
                !empty($e['seo_noindex']),
                isset($e['og_title']) && is_string($e['og_title']) && trim($e['og_title']) !== '' ? trim($e['og_title']) : null,
                isset($e['og_description']) && is_string($e['og_description']) && trim($e['og_description']) !== '' ? trim($e['og_description']) : null,
                $ogId,
                isset($e['twitter_title']) && is_string($e['twitter_title']) && trim($e['twitter_title']) !== '' ? trim($e['twitter_title']) : null,
                isset($e['twitter_description']) && is_string($e['twitter_description']) && trim($e['twitter_description']) !== '' ? trim($e['twitter_description']) : null,
                $twId,
                $schemaJ,
                $pub,
                null,
                null,
                null
            );
            $fieldMap = $e['field_values'] ?? [];
            if (is_array($fieldMap)) {
                $byKey = [];
                foreach ($this->fields->forTypeOrdered($type->id) as $f) {
                    $byKey[$f->fieldKey] = $f->id;
                }
                foreach ($fieldMap as $k => $v) {
                    if (!is_string($k) || !isset($byKey[$k])) {
                        continue;
                    }
                    $str = is_string($v) ? $v : (is_scalar($v) ? (string) $v : null);
                    $this->entryValues->upsert($eid, $byKey[$k], $str);
                }
            }
            $applied[] = 'entry ' . $slug;

            $tmap = $e['taxonomy_terms'] ?? null;
            if (is_array($tmap) && $eid > 0) {
                $allTermIds = [];
                foreach ($tmap as $taxSlug => $termSlugs) {
                    if (!is_string($taxSlug) || $taxSlug === '' || !is_array($termSlugs)) {
                        continue;
                    }
                    $tx = $this->tax->findByContentTypeAndSlug($type->id, $taxSlug);
                    if ($tx === null) {
                        continue;
                    }
                    foreach ($termSlugs as $ts) {
                        if (!is_string($ts) || $ts === '') {
                            continue;
                        }
                        $term = $this->terms->findByTaxonomyAndSlug($tx->id, $ts);
                        if ($term !== null) {
                            $allTermIds[] = $term->id;
                        }
                    }
                }
                if ($allTermIds !== []) {
                    $this->entryTaxonomy->replaceForEntry($eid, $allTermIds);
                }
            }
        }
    }

    /**
     * @param list<mixed> $pages
     * @param list<string> $applied
     * @param list<string> $warnings
     */
    private function importPages(array $pages, bool $merge, array &$applied, array &$warnings, bool $run): void
    {
        if (!$run) {
            return;
        }
        foreach ($pages as $p) {
            if (!is_array($p)) {
                continue;
            }
            $slug = trim((string) ($p['slug'] ?? ''));
            $title = trim((string) ($p['title'] ?? ''));
            if ($slug === '' || $title === '') {
                continue;
            }
            $content = isset($p['content']) && is_string($p['content']) ? $p['content'] : '';
            $status = (string) ($p['status'] ?? 'draft');
            if (!in_array($status, WorkflowService::STATUSES, true)) {
                $status = 'draft';
            }
            $seoTitle = isset($p['seo_title']) && is_string($p['seo_title']) && trim($p['seo_title']) !== '' ? trim($p['seo_title']) : null;
            $seoDesc = isset($p['seo_description']) && is_string($p['seo_description']) && trim($p['seo_description']) !== '' ? trim($p['seo_description']) : null;
            $tagsJson = null;
            if (isset($p['tags'])) {
                if (is_array($p['tags'])) {
                    $slugs = [];
                    foreach ($p['tags'] as $t) {
                        $slugs[] = trim((string) $t);
                    }
                    $tagsJson = PageTagParser::toJson($slugs);
                } elseif (is_string($p['tags'])) {
                    $tagsJson = PageTagParser::toJson(PageTagParser::parseCommaSeparated($p['tags']));
                }
            }
            if ($this->pages->findBySlug($slug) !== null) {
                if ($merge) {
                    $warnings[] = 'Page slug exists, skipped: ' . $slug;
                } else {
                    $warnings[] = 'Skipped existing page: ' . $slug;
                }

                continue;
            }
            $featuredId = $this->resolveBlueprintPageFeaturedImageId($p);
            $canon = isset($p['canonical_url']) && is_string($p['canonical_url']) && trim($p['canonical_url']) !== '' ? trim($p['canonical_url']) : null;
            $ogId = isset($p['og_image_id']) && is_numeric($p['og_image_id']) ? (int) $p['og_image_id'] : null;
            $twId = isset($p['twitter_image_id']) && is_numeric($p['twitter_image_id']) ? (int) $p['twitter_image_id'] : null;
            $ogId = $this->blueprintImageMediaIdOrNull($ogId);
            $twId = $this->blueprintImageMediaIdOrNull($twId);
            $schemaJ = $this->blueprintSchemaJsonOrNull($p['schema_json'] ?? null, 'Page "' . $slug . '" schema_json', $warnings);
            $pageId = $this->pages->insert(
                $title,
                $slug,
                $seoTitle,
                $seoDesc,
                null,
                $tagsJson,
                $featuredId,
                $canon,
                !empty($p['seo_noindex']),
                isset($p['og_title']) && is_string($p['og_title']) && trim($p['og_title']) !== '' ? trim($p['og_title']) : null,
                isset($p['og_description']) && is_string($p['og_description']) && trim($p['og_description']) !== '' ? trim($p['og_description']) : null,
                $ogId,
                isset($p['twitter_title']) && is_string($p['twitter_title']) && trim($p['twitter_title']) !== '' ? trim($p['twitter_title']) : null,
                isset($p['twitter_description']) && is_string($p['twitter_description']) && trim($p['twitter_description']) !== '' ? trim($p['twitter_description']) : null,
                $twId,
                $schemaJ,
                $content,
                $status,
                null,
                null,
                null
            );
            $applied[] = 'page ' . $slug;
            if (isset($p['sections']) && is_array($p['sections']) && $p['sections'] !== []) {
                $n = $this->importPageSections($pageId, $p['sections']);
                if ($n > 0) {
                    $applied[] = 'page sections for ' . $slug . ' (' . $n . ' blocks)';
                }
            }
        }
    }

    /**
     * @param list<mixed> $sections
     */
    private function importPageSections(int $pageId, array $sections): int
    {
        $blocks = [];
        foreach ($sections as $block) {
            if (is_array($block)) {
                $blocks[] = $block;
            }
        }
        usort(
            $blocks,
            static fn (array $a, array $b): int => ((int) ($a['sort_order'] ?? 0)) <=> ((int) ($b['sort_order'] ?? 0))
        );
        $n = 0;
        $sort = 0;
        foreach ($blocks as $block) {
            $key = trim((string) ($block['type'] ?? $block['section_key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $data = isset($block['data']) && is_array($block['data']) ? $block['data'] : [];
            $opts = isset($block['options']) && is_array($block['options']) ? $block['options'] : [];
            $r = $this->sectionSchema->validate($key, $data, $opts);
            if ($r['errors'] !== []) {
                continue;
            }
            $this->pageSections->insert($pageId, $sort, $key, $r['data'], $r['options']);
            ++$sort;
            ++$n;
        }

        return $n;
    }

    /**
     * @param list<mixed> $rows
     * @param list<string> $applied
     * @param list<string> $warnings
     */
    private function importRedirects(array $rows, array &$applied, array &$warnings, bool $run): void
    {
        if (!$run || $rows === []) {
            return;
        }
        $repo = new RedirectRepository($this->pdo);
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $from = trim((string) ($row['from_path'] ?? ''));
            $to = trim((string) ($row['to_url'] ?? ''));
            if ($from === '' || $to === '') {
                $warnings[] = 'Skipped redirect row (missing from_path or to_url).';

                continue;
            }
            $code = (int) ($row['status_code'] ?? 301);
            $repo->upsertPath($from, $to, $code);
            $applied[] = 'redirect ' . $from;
        }
    }

    /**
     * Settings store homepage as page id; blueprints carry slug so `/` works after import on a new database.
     *
     * @param callable(callable(): void): void $exec
     * @param list<string> $applied
     * @param list<string> $warnings
     */
    private function applyPublicHomepageFromBlueprintSlug(
        array $payload,
        bool $homepageSlugKeyPresent,
        callable $exec,
        array &$applied,
        array &$warnings,
        bool $run
    ): void {
        if (!$homepageSlugKeyPresent) {
            return;
        }
        $raw = $payload['public_homepage_page_slug'] ?? '';
        if (!is_string($raw)) {
            return;
        }
        $slug = trim($raw);
        if (!$run) {
            $applied[] = $slug === ''
                ? 'would set public homepage to theme default'
                : 'would set public homepage to page slug: ' . $slug;

            return;
        }
        if ($slug === '') {
            $exec(function (): void {
                $this->settingsRepo->upsert('public_homepage_page_id', '', true);
            });
            $applied[] = 'public homepage: theme default (page/home.twig)';

            return;
        }
        $pg = $this->pages->findBySlug($slug);
        if ($pg === null) {
            $warnings[] = 'Blueprint homepage: no page with slug "' . $slug . '" — public homepage cleared.';
            $exec(function (): void {
                $this->settingsRepo->upsert('public_homepage_page_id', '', true);
            });

            return;
        }
        $exec(function () use ($pg): void {
            $this->settingsRepo->upsert('public_homepage_page_id', (string) $pg->id, true);
        });
        $applied[] = 'public homepage → page slug "' . $slug . '"';
        if ($pg->status !== 'published') {
            $warnings[] = 'Homepage page "' . $slug . '" is not published; "/" will use the theme default until you publish it.';
        }
    }

    /**
     * @param list<string> $warnings
     */
    private function clearStalePublicHomepagePageId(array &$warnings): void
    {
        $all = $this->settingsRepo->allKeyValues();
        $raw = trim((string) ($all['public_homepage_page_id'] ?? ''));
        if ($raw === '' || !ctype_digit($raw)) {
            return;
        }
        if ($this->pages->findById((int) $raw) === null) {
            $this->settingsRepo->upsert('public_homepage_page_id', '', true);
            $warnings[] = 'Cleared invalid public_homepage_page_id (page no longer exists).';
        }
    }

    /**
     * @param list<string> $warnings
     */
    private function blueprintSchemaJsonOrNull(mixed $raw, string $context, array &$warnings): ?string
    {
        if (!is_string($raw)) {
            return null;
        }
        $norm = SeoFormParser::normalizeSchemaJsonForStorage($raw);
        if ($norm['error'] !== null) {
            $warnings[] = $context . ': ' . $norm['error'];

            return null;
        }

        return $norm['value'];
    }

    /**
     * @param array<string, mixed> $p
     */
    private function resolveBlueprintPageFeaturedImageId(array $p): ?int
    {
        if (!isset($p['featured_image_id']) || !is_numeric($p['featured_image_id'])) {
            return null;
        }

        return $this->blueprintImageMediaIdOrNull((int) $p['featured_image_id']);
    }

    /**
     * Blueprint exports may carry media ids from another database; only ids that exist here are kept.
     *
     * @return positive-int|null
     */
    private function blueprintImageMediaIdOrNull(?int $id): ?int
    {
        if ($id === null || $id <= 0) {
            return null;
        }
        $stmt = $this->pdo->prepare("SELECT 1 FROM cms_media WHERE id = ? AND mime_type LIKE 'image/%' LIMIT 1");
        $stmt->execute([$id]);

        return $stmt->fetchColumn() ? $id : null;
    }
}
