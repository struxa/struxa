<?php

declare(strict_types=1);

namespace App\Section;

/** Core block types shipped with Struxa. */
final class CoreSectionDefinitionProvider implements SectionDefinitionProviderInterface
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public function definitions(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $optPadding = [
            ['key' => 'padding', 'type' => 'string', 'label' => 'Vertical padding', 'required' => false, 'enum' => ['comfortable', 'compact', 'spacious']],
        ];
        $optBg = [
            ['key' => 'background', 'type' => 'string', 'label' => 'Background', 'required' => false, 'enum' => ['default', 'muted', 'contrast']],
        ];
        $standardOpts = array_merge($optPadding, $optBg);
        $standardOptDefaults = ['padding' => 'comfortable', 'background' => 'default'];

        $cache = [
            'queue_marketing_hero' => [
                'label' => 'Queue: Marketing hero',
                'sort_order' => 3,
                'template' => 'sections/queue_marketing_hero.twig',
                'schema' => [
                    ['key' => 'kicker', 'type' => 'text', 'label' => 'Kicker (leave blank to use Site tagline from Settings, or the fallback below)', 'required' => false, 'max' => 400],
                    ['key' => 'kicker_fallback', 'type' => 'text', 'label' => 'Kicker when tagline is empty', 'required' => false, 'max' => 400],
                    ['key' => 'title_line_1', 'type' => 'string', 'label' => 'Title line 1', 'required' => true, 'max' => 200],
                    ['key' => 'title_line_2_before', 'type' => 'string', 'label' => 'Title line 2 — text before highlight', 'required' => false, 'max' => 120],
                    ['key' => 'highlight_word', 'type' => 'string', 'label' => 'Title line 2 — highlighted word', 'required' => false, 'max' => 80],
                    ['key' => 'title_line_2_after', 'type' => 'string', 'label' => 'Title line 2 — text after highlight', 'required' => false, 'max' => 120],
                    ['key' => 'badges_json', 'type' => 'json', 'label' => 'Badges (JSON array of strings)', 'required' => true, 'json_hint' => '["Pages","Types","Themes"]'],
                    ['key' => 'lead_use_branded_opening', 'type' => 'bool', 'label' => 'Lead: start with site name from Settings', 'required' => false],
                    ['key' => 'lead_branded_rest', 'type' => 'text', 'label' => 'Lead: text after site name (plain)', 'required' => false, 'max' => 500],
                    ['key' => 'lead_html', 'type' => 'html', 'label' => 'Lead: full HTML (only if site-name opening is off)', 'required' => false],
                    ['key' => 'chips_json', 'type' => 'json', 'label' => 'Chips (JSON array of strings)', 'required' => true, 'json_hint' => '["Chip one","Chip two"]'],
                    ['key' => 'primary_cta_label', 'type' => 'string', 'label' => 'Primary CTA label', 'required' => false, 'max' => 80],
                    ['key' => 'primary_cta_url', 'type' => 'url', 'label' => 'Primary CTA URL', 'required' => false],
                    ['key' => 'secondary_cta_label', 'type' => 'string', 'label' => 'Secondary CTA label', 'required' => false, 'max' => 80],
                    ['key' => 'secondary_cta_url', 'type' => 'url', 'label' => 'Secondary CTA URL', 'required' => false],
                    ['key' => 'show_cms_mock', 'type' => 'bool', 'label' => 'Show CMS editor illustration', 'required' => false],
                ],
                'option_schema' => $standardOpts,
                'defaults' => [
                    'kicker' => '',
                    'kicker_fallback' => 'Honest structure beats vague “looks fine” — for your site and your editors.',
                    'title_line_1' => 'You ship the product.',
                    'title_line_2_before' => 'Now ship the ',
                    'highlight_word' => 'experience',
                    'title_line_2_after' => '.',
                    'badges_json' => ['Pages', 'Types', 'Themes'],
                    'lead_use_branded_opening' => true,
                    'lead_branded_rest' => 'runs on Struxa — structured content, real Twig templates, and a public theme you can swap without migrating data.',
                    'lead_html' => '',
                    'chips_json' => ['Visual page builder', 'First-class types & taxonomies', 'Theme inheritance'],
                    'primary_cta_label' => 'Get started free',
                    'primary_cta_url' => '/register',
                    'secondary_cta_label' => 'Sign in',
                    'secondary_cta_url' => '/login',
                    'show_cms_mock' => true,
                ],
                'option_defaults' => $standardOptDefaults,
            ],
            'queue_marketing_stats' => [
                'label' => 'Queue: Stat bar',
                'sort_order' => 4,
                'template' => 'sections/queue_marketing_stats.twig',
                'schema' => [
                    ['key' => 'queue_stats_json', 'type' => 'json', 'label' => 'Stats (JSON)', 'required' => true,
                        'json_hint' => '[{"heading":"Model","text":"Entries + routes"}] or [{"k":"…","v":"…"}]',
                    ],
                ],
                'option_schema' => $standardOpts,
                'defaults' => [
                    'queue_stats_json' => [
                        ['heading' => 'Model', 'text' => 'Entries + routes'],
                        ['heading' => 'Surface', 'text' => 'Blog · catalog · CMS pages'],
                        ['heading' => 'You keep', 'text' => 'Code + database'],
                    ],
                ],
                'option_defaults' => $standardOptDefaults,
            ],
            'queue_marketing_note' => [
                'label' => 'Queue: Footnote',
                'sort_order' => 5,
                'template' => 'sections/queue_marketing_note.twig',
                'schema' => [
                    ['key' => 'body_html', 'type' => 'html', 'label' => 'Footnote (HTML)', 'required' => true],
                ],
                'option_schema' => $standardOpts,
                'defaults' => [
                    'body_html' => '<p>Branding lives in <strong>Settings</strong>. This look ships from <code>themes/queue</code> (extends <code>themes/default</code>).</p>',
                ],
                'option_defaults' => $standardOptDefaults,
            ],
            'hero' => [
                'label' => 'Hero',
                'sort_order' => 10,
                'template' => 'sections/hero.twig',
                'schema' => [
                    ['key' => 'headline', 'type' => 'string', 'label' => 'Headline', 'required' => true, 'max' => 200],
                    ['key' => 'subheadline', 'type' => 'text', 'label' => 'Subheadline', 'required' => false, 'max' => 500],
                    ['key' => 'cta_label', 'type' => 'string', 'label' => 'Primary CTA label', 'required' => false, 'max' => 80],
                    ['key' => 'cta_url', 'type' => 'url', 'label' => 'Primary CTA URL', 'required' => false],
                    ['key' => 'secondary_cta_label', 'type' => 'string', 'label' => 'Secondary CTA label', 'required' => false, 'max' => 80],
                    ['key' => 'secondary_cta_url', 'type' => 'url', 'label' => 'Secondary CTA URL', 'required' => false],
                    ['key' => 'image_media_id', 'type' => 'image_id', 'label' => 'Hero image (media ID)', 'required' => false],
                    ['key' => 'overlay_dark', 'type' => 'bool', 'label' => 'Dark overlay on image', 'required' => false],
                ],
                'option_schema' => $standardOpts,
                'defaults' => [
                    'headline' => 'Build something remarkable',
                    'subheadline' => 'Structured sections, premium themes, and blueprints you can ship.',
                    'cta_label' => 'Get started',
                    'cta_url' => '#',
                    'secondary_cta_label' => '',
                    'secondary_cta_url' => '',
                    'image_media_id' => null,
                    'overlay_dark' => false,
                ],
                'option_defaults' => $standardOptDefaults,
            ],
            'features_grid' => [
                'label' => 'Features grid',
                'sort_order' => 20,
                'template' => 'sections/features_grid.twig',
                'schema' => [
                    ['key' => 'eyebrow', 'type' => 'string', 'label' => 'Eyebrow', 'required' => false, 'max' => 80],
                    ['key' => 'title', 'type' => 'string', 'label' => 'Title', 'required' => true, 'max' => 200],
                    ['key' => 'intro_html', 'type' => 'html', 'label' => 'Intro (HTML)', 'required' => false],
                    ['key' => 'items_json', 'type' => 'json', 'label' => 'Features (JSON)', 'required' => true, 'json_hint' => '[{"title":"…","body":"…","icon_media_id":null,"badge":""}]'],
                ],
                'option_schema' => $standardOpts,
                'defaults' => [
                    'eyebrow' => 'Why us',
                    'title' => 'Everything you need',
                    'intro_html' => '<p>Short supporting copy for this block.</p>',
                    'items_json' => [
                        ['title' => 'Fast', 'body' => 'Optimized delivery and caching-friendly output.', 'icon_media_id' => null, 'badge' => 'FREE'],
                        ['title' => 'Structured', 'body' => 'Sections stay on-brand; no arbitrary canvas chaos.', 'icon_media_id' => null, 'badge' => 'COMING SOON'],
                        ['title' => 'Portable', 'body' => 'Export layouts with blueprints for new projects.', 'icon_media_id' => null, 'badge' => 'FREE'],
                    ],
                ],
                'option_defaults' => $standardOptDefaults,
            ],
            'content_type_cards' => [
                'label' => 'Content type cards',
                'sort_order' => 25,
                'template' => 'sections/content_type_cards.twig',
                'schema' => [
                    ['key' => 'content_type_slug', 'type' => 'string', 'label' => 'Content type URL slug (e.g. products — must match Content types; needs “Public route” on)', 'required' => true, 'max' => 64],
                    ['key' => 'limit', 'type' => 'string', 'label' => 'Max entries (1–24)', 'required' => false, 'max' => 3],
                    ['key' => 'layout_style', 'type' => 'string', 'label' => 'Layout', 'required' => false, 'enum' => ['auto', 'catalog', 'journal']],
                    ['key' => 'eyebrow', 'type' => 'string', 'label' => 'Eyebrow', 'required' => false, 'max' => 80],
                    ['key' => 'title', 'type' => 'string', 'label' => 'Heading', 'required' => true, 'max' => 200],
                    ['key' => 'intro_html', 'type' => 'html', 'label' => 'Intro (HTML)', 'required' => false],
                    ['key' => 'empty_message', 'type' => 'text', 'label' => 'Empty state message', 'required' => false, 'max' => 300],
                    ['key' => 'read_more_label', 'type' => 'string', 'label' => 'Card CTA label', 'required' => false, 'max' => 80],
                    ['key' => 'card_tag_fallback', 'type' => 'string', 'label' => 'Card tag when no date', 'required' => false, 'max' => 40],
                    ['key' => 'show_view_all', 'type' => 'bool', 'label' => 'Show “View all” link', 'required' => false],
                    ['key' => 'view_all_path', 'type' => 'string', 'label' => 'View all URL path (optional; default: /{type slug})', 'required' => false, 'max' => 200],
                    ['key' => 'view_all_label', 'type' => 'string', 'label' => 'View all link label', 'required' => false, 'max' => 80],
                ],
                'option_schema' => $standardOpts,
                'defaults' => [
                    'content_type_slug' => 'products',
                    'limit' => '6',
                    'layout_style' => 'auto',
                    'eyebrow' => '',
                    'title' => 'Featured products',
                    'intro_html' => '',
                    'empty_message' => '',
                    'read_more_label' => '',
                    'card_tag_fallback' => '',
                    'show_view_all' => true,
                    'view_all_path' => '',
                    'view_all_label' => 'View all',
                ],
                'option_defaults' => $standardOptDefaults,
            ],
            'pricing_table' => [
                'label' => 'Pricing table',
                'sort_order' => 30,
                'template' => 'sections/pricing_table.twig',
                'schema' => [
                    ['key' => 'title', 'type' => 'string', 'label' => 'Title', 'required' => true, 'max' => 200],
                    ['key' => 'intro_html', 'type' => 'html', 'label' => 'Intro (HTML)', 'required' => false],
                    ['key' => 'plans_json', 'type' => 'json', 'label' => 'Plans (JSON)', 'required' => true,
                        'json_hint' => '[{"name":"…","price":"…","cadence":"…","bullets":["…"],"cta_label":"…","cta_url":"…","highlighted":false}]',
                    ],
                ],
                'option_schema' => $standardOpts,
                'defaults' => [
                    'title' => 'Simple pricing',
                    'intro_html' => '',
                    'plans_json' => [
                        ['name' => 'Starter', 'price' => '$29', 'cadence' => 'per month', 'bullets' => ['Core features', 'Email support'], 'cta_label' => 'Choose', 'cta_url' => '#', 'highlighted' => false],
                        ['name' => 'Pro', 'price' => '$79', 'cadence' => 'per month', 'bullets' => ['Everything in Starter', 'Priority support'], 'cta_label' => 'Choose', 'cta_url' => '#', 'highlighted' => true],
                    ],
                ],
                'option_defaults' => $standardOptDefaults,
            ],
            'testimonials' => [
                'label' => 'Testimonials',
                'sort_order' => 40,
                'template' => 'sections/testimonials.twig',
                'schema' => [
                    ['key' => 'title', 'type' => 'string', 'label' => 'Title', 'required' => true, 'max' => 200],
                    ['key' => 'quotes_json', 'type' => 'json', 'label' => 'Quotes (JSON)', 'required' => true,
                        'json_hint' => '[{"quote":"…","attribution":"…","role":"…"}]',
                    ],
                ],
                'option_schema' => $standardOpts,
                'defaults' => [
                    'title' => 'Loved by teams',
                    'quotes_json' => [
                        ['quote' => 'The structured builder keeps marketing and engineering aligned.', 'attribution' => 'Alex M.', 'role' => 'VP Marketing'],
                        ['quote' => 'We ship landing pages in hours, not weeks.', 'attribution' => 'Jordan K.', 'role' => 'Founder'],
                    ],
                ],
                'option_defaults' => $standardOptDefaults,
            ],
            'faq' => [
                'label' => 'FAQ',
                'sort_order' => 50,
                'template' => 'sections/faq.twig',
                'schema' => [
                    ['key' => 'title', 'type' => 'string', 'label' => 'Title', 'required' => true, 'max' => 200],
                    ['key' => 'items_json', 'type' => 'json', 'label' => 'Questions (JSON)', 'required' => true,
                        'json_hint' => '[{"question":"…","answer_html":"<p>…</p>"}]',
                    ],
                ],
                'option_schema' => $standardOpts,
                'defaults' => [
                    'title' => 'Frequently asked questions',
                    'items_json' => [
                        ['question' => 'Can I use my own theme?', 'answer_html' => '<p>Yes. Sections resolve to theme templates with core fallbacks.</p>'],
                        ['question' => 'Is this a page builder?', 'answer_html' => '<p>It is a structured section system — reusable blocks, not a freeform canvas.</p>'],
                    ],
                ],
                'option_defaults' => $standardOptDefaults,
            ],
            'cta_banner' => [
                'label' => 'CTA banner',
                'sort_order' => 60,
                'template' => 'sections/cta_banner.twig',
                'schema' => [
                    ['key' => 'headline', 'type' => 'string', 'label' => 'Headline', 'required' => true, 'max' => 200],
                    ['key' => 'body', 'type' => 'text', 'label' => 'Body', 'required' => false, 'max' => 600],
                    ['key' => 'cta_label', 'type' => 'string', 'label' => 'CTA label', 'required' => false, 'max' => 80],
                    ['key' => 'cta_url', 'type' => 'url', 'label' => 'CTA URL', 'required' => false],
                    ['key' => 'style', 'type' => 'string', 'label' => 'Style', 'required' => false, 'enum' => ['primary', 'muted']],
                ],
                'option_schema' => $standardOpts,
                'defaults' => [
                    'headline' => 'Ready to launch?',
                    'body' => 'Start with a blueprint or craft your own section stack.',
                    'cta_label' => 'Contact us',
                    'cta_url' => '#',
                    'style' => 'primary',
                ],
                'option_defaults' => $standardOptDefaults,
            ],
            'rich_text' => [
                'label' => 'Rich text',
                'sort_order' => 70,
                'template' => 'sections/rich_text.twig',
                'schema' => [
                    ['key' => 'body_html', 'type' => 'html', 'label' => 'Content (HTML)', 'required' => true],
                ],
                'option_schema' => $standardOpts,
                'defaults' => [
                    'body_html' => '<p>Add narrative content with headings, lists, and links.</p>',
                ],
                'option_defaults' => $standardOptDefaults,
            ],
            'image_text' => [
                'label' => 'Image + text',
                'sort_order' => 80,
                'template' => 'sections/image_text.twig',
                'schema' => [
                    ['key' => 'image_media_id', 'type' => 'image_id', 'label' => 'Image (media ID)', 'required' => false],
                    ['key' => 'title', 'type' => 'string', 'label' => 'Title', 'required' => true, 'max' => 200],
                    ['key' => 'body_html', 'type' => 'html', 'label' => 'Body (HTML)', 'required' => false],
                    ['key' => 'image_position', 'type' => 'string', 'label' => 'Image position', 'required' => false, 'enum' => ['left', 'right']],
                ],
                'option_schema' => $standardOpts,
                'defaults' => [
                    'image_media_id' => null,
                    'title' => 'Tell your story',
                    'body_html' => '<p>Pair photography with copy in a responsive split layout.</p>',
                    'image_position' => 'left',
                ],
                'option_defaults' => $standardOptDefaults,
            ],
            'stats_grid' => [
                'label' => 'Stats grid',
                'sort_order' => 90,
                'template' => 'sections/stats_grid.twig',
                'schema' => [
                    ['key' => 'title', 'type' => 'string', 'label' => 'Title', 'required' => false, 'max' => 200],
                    ['key' => 'stats_json', 'type' => 'json', 'label' => 'Stats (JSON)', 'required' => true,
                        'json_hint' => '[{"value":"99%","label":"Uptime"}]',
                    ],
                ],
                'option_schema' => $standardOpts,
                'defaults' => [
                    'title' => 'By the numbers',
                    'stats_json' => [
                        ['value' => '10k+', 'label' => 'Monthly readers'],
                        ['value' => '50+', 'label' => 'Enterprise clients'],
                        ['value' => '24/7', 'label' => 'Support coverage'],
                    ],
                ],
                'option_defaults' => $standardOptDefaults,
            ],
            'comparison_table' => [
                'label' => 'Comparison table',
                'sort_order' => 100,
                'template' => 'sections/comparison_table.twig',
                'schema' => [
                    ['key' => 'title', 'type' => 'string', 'label' => 'Title', 'required' => true, 'max' => 200],
                    ['key' => 'columns_json', 'type' => 'json', 'label' => 'Column labels (JSON array of strings)', 'required' => true],
                    ['key' => 'rows_json', 'type' => 'json', 'label' => 'Rows (JSON)', 'required' => true,
                        'json_hint' => '[{"feature":"…","cells":["Yes","No","Yes"]}]',
                    ],
                ],
                'option_schema' => $standardOpts,
                'defaults' => [
                    'title' => 'Compare plans',
                    'columns_json' => ['Starter', 'Pro', 'Enterprise'],
                    'rows_json' => [
                        ['feature' => 'Sections', 'cells' => ['✓', '✓', '✓']],
                        ['feature' => 'Blueprints', 'cells' => ['—', '✓', '✓']],
                        ['feature' => 'SSO', 'cells' => ['—', '—', '✓']],
                    ],
                ],
                'option_defaults' => $standardOptDefaults,
            ],

            'spacer' => [
                'label' => 'Spacer',
                'sort_order' => 5,
                'template' => 'sections/spacer.twig',
                'schema' => [
                    ['key' => 'size', 'type' => 'string', 'label' => 'Size', 'required' => false, 'enum' => ['sm', 'md', 'lg', 'xl']],
                ],
                'option_schema' => [],
                'defaults' => [
                    'size' => 'md',
                ],
                'option_defaults' => [],
            ],
            'forms_embed' => [
                'label' => 'Form embed',
                'sort_order' => 46,
                'category' => 'Forms',
                'description' => 'Embed a Struxa form by slug.',
                'template' => 'sections/form_embed.twig',
                'schema' => [
                    ['key' => 'form_slug', 'type' => 'string', 'label' => 'Form slug', 'required' => true, 'max' => 120],
                    ['key' => 'title', 'type' => 'string', 'label' => 'Heading (optional)', 'required' => false, 'max' => 200],
                    ['key' => 'lead', 'type' => 'text', 'label' => 'Intro text (optional)', 'required' => false, 'max' => 500],
                ],
                'option_schema' => [
                    ['key' => 'padding', 'type' => 'string', 'label' => 'Vertical padding', 'required' => false, 'enum' => ['comfortable', 'compact', 'spacious']],
                    ['key' => 'background', 'type' => 'string', 'label' => 'Background', 'required' => false, 'enum' => ['default', 'muted', 'contrast']],
                ],
                'defaults' => [
                    'form_slug' => '',
                    'title' => '',
                    'lead' => '',
                ],
                'option_defaults' => [
                    'padding' => 'comfortable',
                    'background' => 'default',
                ],
                'hosts' => ['page', 'content_entry'],
            ],
        ];

        return $cache;
    }
}
