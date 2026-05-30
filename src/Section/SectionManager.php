<?php

declare(strict_types=1);

namespace App\Section;

/**
 * Registry of reusable section types (palette + schema metadata).
 */
final class SectionManager
{
    private readonly SectionDefinitionRegistry $registry;

    public function __construct(?SectionDefinitionRegistry $registry = null)
    {
        $this->registry = $registry ?? SectionDefinitionRegistry::instance();
    }

    public function registerProvider(SectionDefinitionProviderInterface $provider): void
    {
        $this->registry->registerProvider($provider);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function definitions(): array
    {
        return $this->registry->definitions();
    }

    public function definition(string $sectionKey): ?array
    {
        return $this->registry->definition($sectionKey);
    }

    public function has(string $sectionKey): bool
    {
        return $this->registry->has($sectionKey);
    }

    /**
     * @return list<array{key: string, label: string, sort_order: int, category: string, category_label: string, icon: string, description: string}>
     */
    public function palette(?string $host = null): array
    {
        return $this->registry->palette($host);
    }

    /**
     * @return list<array{category: string, label: string, blocks: list<array<string, mixed>>}>
     */
    public function paletteGrouped(?string $host = null): array
    {
        return $this->registry->paletteGrouped($host);
    }

    /**
     * @return array<string, mixed>
     */
    public function defaultData(string $sectionKey): array
    {
        return $this->registry->defaultData($sectionKey);
    }

    /**
     * @return array<string, mixed>
     */
    public function defaultOptions(string $sectionKey): array
    {
        return $this->registry->defaultOptions($sectionKey);
    }
}
