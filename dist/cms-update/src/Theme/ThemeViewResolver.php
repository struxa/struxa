<?php

declare(strict_types=1);

namespace App\Theme;

/**
 * Builds Twig filesystem paths for Slim Twig::create().
 *
 * Order is core first, then {@see ThemeManager::viewPathTailsForManifest()} (active theme, then parents
 * nearest → furthest). The FilesystemLoader uses the first path where a template file exists, so core
 * wins over themes when both define the same name — do not duplicate storefront-only templates in core
 * if themes should own them (e.g. `page/home.twig`, `page/show.twig`). Per-type overrides such as content/blog/show.twig
 * resolve from the theme when core has no file at that path.
 *
 * Layout contract: see {@see \App\Theme\PublicLayoutContract}. Never add `templates/layouts/base.twig` in
 * core — it would shadow every theme’s `layouts/base.twig`. Theme storefront templates should extend
 * `layouts/base.twig` (or `theme_layout()`), not `base.twig`, or they incorrectly get core’s marketing shell.
 */
final class ThemeViewResolver
{
    /**
     * @return list<string> at least core templates directory
     */
    public static function twigLoaderPaths(ThemeManager $themes, string $coreTemplatesDirectory): array
    {
        $core = rtrim($coreTemplatesDirectory, '/\\');
        $tails = [];

        $active = $themes->findBySlug($themes->activeSlug());
        if ($active === null) {
            $active = $themes->findBySlug(ThemeHttpConfig::FALLBACK_THEME_SLUG);
        }

        if ($active !== null) {
            $tails = $themes->viewPathTailsForManifest($active);
        } else {
            $fallbackViews = $themes->viewsPathForSlug(ThemeHttpConfig::FALLBACK_THEME_SLUG);
            if ($fallbackViews !== null) {
                $tails = [$fallbackViews];
            }
        }

        return array_merge([$core], $tails);
    }
}
