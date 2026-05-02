<?php

declare(strict_types=1);

namespace App\Blueprint;

use App\Content\ContentEntryRepository;
use App\Content\ContentEntryValueRepository;
use App\Content\ContentFieldRepository;
use App\Content\ContentTypeRepository;
use App\Menu\MenuRepository;
use App\Menu\MenuItemRepository;
use App\Page\PageRepository;
use App\Section\PageSectionRepository;
use App\Plugin\PluginRepository;
use App\Settings\SettingsRepository;
use App\Settings\SiteSettingsService;
use App\SiteProfile\SiteProfileRepository;
use App\Taxonomy\TaxonomyRepository;
use App\Taxonomy\TaxonomyTermRepository;
use App\Seo\RedirectRepository;
use App\Theme\ThemeManager;

/**
 * Maps DB state into portable JSON-ready arrays.
 */
final class StructureCollector
{
    public function __construct(
        private readonly ContentTypeRepository $types,
        private readonly ContentFieldRepository $fields,
        private readonly TaxonomyRepository $tax,
        private readonly TaxonomyTermRepository $terms,
        private readonly MenuRepository $menus,
        private readonly MenuItemRepository $menuItems,
        private readonly SettingsRepository $settingsRepo,
        private readonly SiteProfileRepository $profile,
        private readonly ThemeManager $themes,
        private readonly PluginRepository $plugins,
        private readonly ContentEntryRepository $entries,
        private readonly ContentEntryValueRepository $entryValues,
        private readonly PageRepository $pages,
        private readonly PageSectionRepository $pageSections,
        private readonly RedirectRepository $redirects,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function collectFull(string $label, bool $includeEntries, int $maxEntriesPerType = 50): array
    {
        $profile = $this->profile->get();

        $out = [
            'cms_blueprint_version' => BlueprintSchemaValidator::VERSION,
            'label' => $label,
            'exported_at' => gmdate('c'),
            'site_profile' => [
                'site_uuid' => $profile['site_uuid'] ?? null,
                'project_name' => $profile['project_name'] ?? '',
            ],
            'settings' => $this->exportSettingsSubset(),
            'active_theme_slug' => $this->themes->activeSlug(),
            'required_plugin_slugs' => $this->plugins->activeSlugs(),
            'content_types' => [],
            'menus' => $this->exportMenus(),
            'content_entries' => [],
            'pages' => $this->exportPages(),
            'redirects' => $this->exportRedirects(),
            /** Resolved after pages import (numeric page ids differ per database). */
            'public_homepage_page_slug' => $this->exportPublicHomepageSlugForBlueprint(),
        ];

        foreach ($this->types->allOrdered() as $type) {
            $row = [
                'slug' => $type->slug,
                'name' => $type->name,
                'icon' => $type->icon,
                'description' => $type->description,
                'has_public_route' => $type->hasPublicRoute,
                'supports_seo' => $type->supportsSeo,
                'supports_featured_image' => $type->supportsFeaturedImage,
                'fields' => [],
                'taxonomies' => [],
            ];
            foreach ($this->fields->forTypeOrdered($type->id) as $f) {
                $row['fields'][] = [
                    'field_key' => $f->fieldKey,
                    'label' => $f->label,
                    'field_type' => $f->fieldType,
                    'placeholder' => $f->placeholder,
                    'help_text' => $f->helpText,
                    'is_required' => $f->isRequired,
                    'default_value' => $f->defaultValue,
                    'options_json' => $f->optionsJson,
                    'sort_order' => $f->sortOrder,
                ];
            }
            foreach ($this->tax->forContentTypeOrdered($type->id) as $tx) {
                $taxRow = [
                    'slug' => $tx->slug,
                    'name' => $tx->name,
                    'description' => $tx->description,
                    'taxonomy_type' => $tx->taxonomyType,
                    'is_hierarchical' => $tx->isHierarchical,
                    'terms' => [],
                ];
                $termList = $this->terms->forTaxonomyOrdered($tx->id);
                $idToSlug = [];
                foreach ($termList as $tm) {
                    $idToSlug[$tm->id] = $tm->slug;
                }
                foreach ($termList as $tm) {
                    $parentSlug = null;
                    if ($tm->parentId !== null && isset($idToSlug[$tm->parentId])) {
                        $parentSlug = $idToSlug[$tm->parentId];
                    }
                    $taxRow['terms'][] = [
                        'slug' => $tm->slug,
                        'name' => $tm->name,
                        'description' => $tm->description,
                        'parent_slug' => $parentSlug,
                        'sort_order' => $tm->sortOrder,
                        'seo_title' => $tm->seoTitle,
                        'seo_description' => $tm->seoDescription,
                        'canonical_url' => $tm->canonicalUrl,
                        'seo_noindex' => $tm->seoNoindex,
                        'og_title' => $tm->ogTitle,
                        'og_description' => $tm->ogDescription,
                        'og_image_id' => $tm->ogImageId,
                        'twitter_title' => $tm->twitterTitle,
                        'twitter_description' => $tm->twitterDescription,
                        'twitter_image_id' => $tm->twitterImageId,
                        'schema_json' => $tm->schemaJson,
                    ];
                }
                $row['taxonomies'][] = $taxRow;
            }
            $out['content_types'][] = $row;

            if ($includeEntries) {
                $out['content_entries'] = array_merge(
                    $out['content_entries'],
                    $this->exportEntriesForType($type->slug, $type->id, $maxEntriesPerType)
                );
            }
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function exportPages(): array
    {
        $out = [];
        foreach ($this->pages->allOrderedByUpdated() as $page) {
            $out[] = [
                'title' => $page->title,
                'slug' => $page->slug,
                'seo_title' => $page->seoTitle,
                'seo_description' => $page->seoDescription,
                'canonical_url' => $page->canonicalUrl,
                'seo_noindex' => $page->seoNoindex,
                'og_title' => $page->ogTitle,
                'og_description' => $page->ogDescription,
                'og_image_id' => $page->ogImageId,
                'twitter_title' => $page->twitterTitle,
                'twitter_description' => $page->twitterDescription,
                'twitter_image_id' => $page->twitterImageId,
                'schema_json' => $page->schemaJson,
                'tags' => $page->tags,
                'featured_image_id' => $page->featuredImageId,
                'content' => $page->content,
                'status' => $page->status,
                'sections' => $this->pageSections->exportBlocksForPage($page->id),
            ];
        }

        return $out;
    }

    /**
     * @return list<array{from_path: string, to_url: string, status_code: int}>
     */
    private function exportRedirects(): array
    {
        $out = [];
        foreach ($this->redirects->allOrdered() as $r) {
            $out[] = [
                'from_path' => (string) ($r['from_path'] ?? ''),
                'to_url' => (string) ($r['to_url'] ?? ''),
                'status_code' => (int) ($r['status_code'] ?? 301),
            ];
        }

        return $out;
    }

    /**
     * @param list<string> $scopes content_types|menus|settings|entries|meta
     * @return array<string, mixed>
     */
    public function collectPartial(array $scopes, bool $includeEntries, int $maxEntriesPerType): array
    {
        $scopes = array_flip($scopes);
        $base = [
            'cms_structure_export_version' => '1.0',
            'exported_at' => gmdate('c'),
        ];
        if (isset($scopes['meta'])) {
            $p = $this->profile->get();
            $base['site_profile'] = [
                'site_uuid' => $p['site_uuid'] ?? null,
                'project_name' => $p['project_name'] ?? '',
                'cms_version_installed' => $p['cms_version_installed'] ?? '',
            ];
            $base['active_theme_slug'] = $this->themes->activeSlug();
            $base['required_plugin_slugs'] = $this->plugins->activeSlugs();
        }
        if (isset($scopes['settings'])) {
            $base['settings'] = $this->exportSettingsSubset();
            $base['public_homepage_page_slug'] = $this->exportPublicHomepageSlugForBlueprint();
        }
        if (isset($scopes['menus'])) {
            $base['menus'] = $this->exportMenus();
        }
        if (isset($scopes['content_types'])) {
            $full = $this->collectFull('partial', false, 0);
            $base['content_types'] = $full['content_types'];
        }
        if ($includeEntries && isset($scopes['entries'])) {
            $entries = [];
            foreach ($this->types->allOrdered() as $type) {
                $entries = array_merge($entries, $this->exportEntriesForType($type->slug, $type->id, $maxEntriesPerType));
            }
            $base['content_entries'] = $entries;
        }

        return $base;
    }

    /**
     * @return array<string, string>
     */
    private function exportSettingsSubset(): array
    {
        $all = $this->settingsRepo->allKeyValues();
        $out = [];
        foreach (SiteSettingsService::MANAGED_KEYS as $k) {
            if ($k === 'public_homepage_page_id') {
                continue;
            }
            if (array_key_exists($k, $all)) {
                $out[$k] = $all[$k];
            }
        }
        foreach (['active_theme', 'cms_panel_title'] as $k) {
            if (array_key_exists($k, $all)) {
                $out[$k] = $all[$k];
            }
        }

        return $out;
    }

    /**
     * Homepage is stored as cms_pages.id in settings; blueprints carry slug so import can remap ids.
     */
    private function exportPublicHomepageSlugForBlueprint(): string
    {
        $all = $this->settingsRepo->allKeyValues();
        $raw = trim((string) ($all['public_homepage_page_id'] ?? ''));
        if ($raw === '' || !ctype_digit($raw)) {
            return '';
        }
        $pg = $this->pages->findById((int) $raw);

        return $pg !== null ? $pg->slug : '';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function exportMenus(): array
    {
        $out = [];
        foreach ($this->menus->allOrdered() as $menu) {
            $items = [];
            foreach ($this->menuItems->forMenuOrdered($menu->id) as $it) {
                $pageSlug = null;
                if ($it->pageId !== null) {
                    $pg = $this->pages->findById($it->pageId);
                    $pageSlug = $pg?->slug;
                }
                $items[] = [
                    'label' => $it->label,
                    'url' => $it->url,
                    'page_slug' => $pageSlug,
                    'sort_order' => $it->sortOrder,
                    'target' => $it->target,
                    'css_class' => $it->cssClass,
                ];
            }
            $out[] = [
                'location' => $menu->location,
                'name' => $menu->name,
                'items' => $items,
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function exportEntriesForType(string $typeSlug, int $typeId, int $limit): array
    {
        $fieldList = $this->fields->forTypeOrdered($typeId);
        $keyById = [];
        foreach ($fieldList as $f) {
            $keyById[$f->id] = $f->fieldKey;
        }
        $rows = $this->entries->forTypeOrdered($typeId, $limit);
        $out = [];
        foreach ($rows as $row) {
            $eid = (int) $row['id'];
            $vals = $this->entryValues->valuesByFieldIdForEntry($eid);
            $byKey = [];
            foreach ($vals as $fid => $v) {
                $k = $keyById[(int) $fid] ?? null;
                if ($k !== null) {
                    $byKey[$k] = $v;
                }
            }
            $out[] = [
                'content_type_slug' => $typeSlug,
                'title' => (string) ($row['title'] ?? ''),
                'slug' => (string) ($row['slug'] ?? ''),
                'status' => (string) ($row['status'] ?? 'draft'),
                'featured_image_id' => isset($row['featured_image_id']) && $row['featured_image_id'] !== null && $row['featured_image_id'] !== ''
                    ? (int) $row['featured_image_id'] : null,
                'seo_title' => $row['seo_title'] ?? null,
                'seo_description' => $row['seo_description'] ?? null,
                'canonical_url' => $row['canonical_url'] ?? null,
                'seo_noindex' => !empty($row['seo_noindex']),
                'og_title' => $row['og_title'] ?? null,
                'og_description' => $row['og_description'] ?? null,
                'og_image_id' => isset($row['og_image_id']) && $row['og_image_id'] !== null && $row['og_image_id'] !== ''
                    ? (int) $row['og_image_id'] : null,
                'twitter_title' => $row['twitter_title'] ?? null,
                'twitter_description' => $row['twitter_description'] ?? null,
                'twitter_image_id' => isset($row['twitter_image_id']) && $row['twitter_image_id'] !== null && $row['twitter_image_id'] !== ''
                    ? (int) $row['twitter_image_id'] : null,
                'schema_json' => $row['schema_json'] ?? null,
                'published_at' => $row['published_at'] ?? null,
                'field_values' => $byKey,
            ];
        }

        return $out;
    }
}
