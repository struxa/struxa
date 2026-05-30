<?php

declare(strict_types=1);

namespace App\Section;

/**
 * Global registry of section block definitions (core + plugins).
 */
final class SectionDefinitionRegistry
{
    private static ?self $instance = null;

    /** @var list<SectionDefinitionProviderInterface> */
    private array $providers = [];

    /** @var array<string, array<string, mixed>>|null */
    private ?array $definitionsCache = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    public function clear(): void
    {
        $this->providers = [];
        $this->definitionsCache = null;
    }

    public function registerProvider(SectionDefinitionProviderInterface $provider): void
    {
        $this->providers[] = $provider;
        $this->definitionsCache = null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function definitions(): array
    {
        return $this->mergedDefinitions();
    }

    public function definition(string $sectionKey): ?array
    {
        return $this->mergedDefinitions()[$sectionKey] ?? null;
    }

    public function has(string $sectionKey): bool
    {
        return isset($this->mergedDefinitions()[$sectionKey]);
    }

    /**
     * @return list<array{key: string, label: string, sort_order: int, category: string, category_label: string, icon: string, description: string}>
     */
    public function palette(): array
    {
        $defs = $this->mergedDefinitions();
        $out = [];
        foreach ($defs as $key => $d) {
            $cat = (string) ($d['category'] ?? 'content');
            $out[] = [
                'key' => $key,
                'label' => (string) $d['label'],
                'sort_order' => (int) $d['sort_order'],
                'category' => $cat,
                'category_label' => SectionBlockCatalog::CATEGORY_LABELS[$cat] ?? ucfirst($cat),
                'icon' => (string) ($d['icon'] ?? 'block'),
                'description' => (string) ($d['description'] ?? ''),
            ];
        }
        usort($out, static fn (array $a, array $b): int => $a['sort_order'] <=> $b['sort_order']);

        return $out;
    }

    /**
     * @return list<array{category: string, label: string, blocks: list<array<string, mixed>>}>
     */
    public function paletteGrouped(): array
    {
        $groups = [];
        foreach ($this->palette() as $item) {
            $cat = $item['category'];
            if (!isset($groups[$cat])) {
                $groups[$cat] = [
                    'category' => $cat,
                    'label' => SectionBlockCatalog::CATEGORY_LABELS[$cat] ?? ucfirst($cat),
                    'blocks' => [],
                ];
            }
            $groups[$cat]['blocks'][] = $item;
        }

        $order = array_keys(SectionBlockCatalog::CATEGORY_LABELS);
        uksort($groups, static function (string $a, string $b) use ($order): int {
            $ia = array_search($a, $order, true);
            $ib = array_search($b, $order, true);
            $ia = $ia === false ? 999 : $ia;
            $ib = $ib === false ? 999 : $ib;

            return $ia <=> $ib;
        });

        return array_values($groups);
    }

    /**
     * @return array<string, mixed>
     */
    public function defaultData(string $sectionKey): array
    {
        $d = $this->definition($sectionKey);

        return $d !== null ? $d['defaults'] : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function defaultOptions(string $sectionKey): array
    {
        $d = $this->definition($sectionKey);

        return $d !== null ? $d['option_defaults'] : [];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function mergedDefinitions(): array
    {
        if ($this->definitionsCache !== null) {
            return $this->definitionsCache;
        }

        $merged = [];
        foreach ($this->providers as $provider) {
            foreach ($provider->definitions() as $key => $def) {
                $merged[$key] = $def;
            }
        }

        $this->definitionsCache = SectionBlockCatalog::enrichDefinitions($merged);

        return $this->definitionsCache;
    }
}
