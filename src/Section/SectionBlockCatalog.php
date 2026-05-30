<?php

declare(strict_types=1);

namespace App\Section;

/**
 * Palette metadata (category, icon, description) for core section types.
 */
final class SectionBlockCatalog
{
    /** @var array<string, string> */
    public const CATEGORY_LABELS = [
        'hero' => 'Hero & headers',
        'content' => 'Content',
        'marketing' => 'Marketing',
        'social' => 'Social proof',
        'layout' => 'Layout',
        'brand' => 'Brand presets',
    ];

    /**
     * @return array<string, array{category: string, icon: string, description: string}>
     */
    public static function meta(): array
    {
        return [
            'queue_marketing_hero' => [
                'category' => 'brand',
                'icon' => 'hero',
                'description' => 'Full-width marketing hero with badges, chips, and CTAs.',
            ],
            'queue_marketing_stats' => [
                'category' => 'brand',
                'icon' => 'stats',
                'description' => 'Horizontal stat bar for key metrics.',
            ],
            'queue_marketing_note' => [
                'category' => 'brand',
                'icon' => 'text',
                'description' => 'Small footnote or disclaimer block.',
            ],
            'hero' => [
                'category' => 'hero',
                'icon' => 'hero',
                'description' => 'Headline, subheadline, CTAs, and optional background image.',
            ],
            'features_grid' => [
                'category' => 'marketing',
                'icon' => 'grid',
                'description' => 'Grid of feature cards with icons and badges.',
            ],
            'content_type_cards' => [
                'category' => 'content',
                'icon' => 'cards',
                'description' => 'Latest entries from a content type as cards.',
            ],
            'pricing_table' => [
                'category' => 'marketing',
                'icon' => 'pricing',
                'description' => 'Pricing tiers with bullets and highlight column.',
            ],
            'testimonials' => [
                'category' => 'social',
                'icon' => 'quote',
                'description' => 'Customer quotes with attribution.',
            ],
            'faq' => [
                'category' => 'content',
                'icon' => 'faq',
                'description' => 'Accordion-style questions and answers.',
            ],
            'cta_banner' => [
                'category' => 'marketing',
                'icon' => 'cta',
                'description' => 'Call-to-action strip with headline and button.',
            ],
            'rich_text' => [
                'category' => 'content',
                'icon' => 'text',
                'description' => 'Free-form HTML content block.',
            ],
            'image_text' => [
                'category' => 'content',
                'icon' => 'image-text',
                'description' => 'Split layout with image and copy.',
            ],
            'stats_grid' => [
                'category' => 'social',
                'icon' => 'stats',
                'description' => 'Highlight key numbers in a grid.',
            ],
            'comparison_table' => [
                'category' => 'marketing',
                'icon' => 'table',
                'description' => 'Feature comparison table across plans.',
            ],
            'spacer' => [
                'category' => 'layout',
                'icon' => 'spacer',
                'description' => 'Vertical spacing between sections.',
            ],
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $definitions
     *
     * @return array<string, array<string, mixed>>
     */
    public static function enrichDefinitions(array $definitions): array
    {
        $meta = self::meta();
        foreach ($definitions as $key => $def) {
            $m = $meta[$key] ?? [
                'category' => 'content',
                'icon' => 'block',
                'description' => 'Reusable page block.',
            ];
            $definitions[$key] = array_merge($def, $m);
        }

        return $definitions;
    }
}
