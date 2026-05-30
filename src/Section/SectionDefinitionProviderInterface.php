<?php

declare(strict_types=1);

namespace App\Section;

/**
 * Supplies block/section type definitions for the page builder registry.
 *
 * @phpstan-type SectionDef array{
 *   label: string,
 *   sort_order: int,
 *   template: string,
 *   schema: list<array<string, mixed>>,
 *   option_schema: list<array<string, mixed>>,
 *   defaults: array<string, mixed>,
 *   option_defaults: array<string, mixed>,
 *   category?: string,
 *   description?: string,
 *   icon?: string
 * }
 */
interface SectionDefinitionProviderInterface
{
    /**
     * @return array<string, SectionDef>
     */
    public function definitions(): array;
}
