<?php

declare(strict_types=1);

namespace App\Section;

use Twig\Environment;

/**
 * Resolves section view names; core ships defaults under templates/sections/.
 * Active theme paths are registered later on the Twig loader, so theme templates override core automatically.
 */
final class SectionTemplateResolver
{
    public function __construct(
        private readonly SectionManager $sections,
    ) {
    }

    public function resolve(Environment $env, string $sectionKey): string
    {
        $def = $this->sections->definition($sectionKey);
        $name = $def !== null && isset($def['template']) && is_string($def['template']) && $def['template'] !== ''
            ? $def['template']
            : ('sections/' . $sectionKey . '.twig');

        if ($env->getLoader()->exists($name)) {
            return $name;
        }

        return $env->getLoader()->exists('sections/_fallback.twig')
            ? 'sections/_fallback.twig'
            : 'sections/_missing.twig';
    }
}
