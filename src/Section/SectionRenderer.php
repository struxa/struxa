<?php

declare(strict_types=1);

namespace App\Section;

use App\Content\RichtextTabsShortcode;
use Twig\Environment;

/**
 * Renders stored page sections using theme-aware Twig resolution.
 */
final class SectionRenderer
{
    public function __construct(
        private readonly SectionManager $sections,
        private readonly SectionTemplateResolver $resolver,
    ) {
    }

    /**
     * @param list<PageSection> $rows
     */
    public function renderPage(Environment $env, array $rows): string
    {
        $html = '';
        foreach ($rows as $row) {
            $html .= $this->renderOne($env, $row);
        }

        return RichtextTabsShortcode::transform($html);
    }

    public function renderOne(Environment $env, PageSection $row): string
    {
        $def = $this->sections->definition($row->sectionKey);
        $label = $def['label'] ?? $row->sectionKey;
        $template = $this->resolver->resolve($env, $row->sectionKey);

        return $env->render($template, [
            'section' => $row->data,
            'options' => $row->options,
            'section_key' => $row->sectionKey,
            'section_label' => $label,
        ]);
    }
}
