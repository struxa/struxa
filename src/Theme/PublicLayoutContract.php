<?php

declare(strict_types=1);

namespace App\Theme;

/**
 * Canonical Twig layout names for the public site. Prevents accidental shadowing:
 *
 * - {@see PUBLIC_ROOT} lives only under core `templates/public/`. Core pages, plugins, and anything
 *   that should use the marketing shell (menus, /css/styles.css, etc.) extend this — not ad-hoc copies.
 * - {@see THEME_SHELL} is defined only under active theme `views/layouts/`. Theme storefront templates
 *   extend this. Do not add `templates/layouts/base.twig` in core: the loader checks core first and would
 *   steal resolution from every theme.
 */
final class PublicLayoutContract
{
    /** Core full-document layout (header shell, global CSS, site_url, etc.). */
    public const PUBLIC_ROOT = 'public/root.twig';

    /**
     * Theme storefront shell (`themes/{slug}/views/layouts/base.twig`).
     * Theme templates should extend this, not `base.twig`, or they incorrectly resolve to core.
     */
    public const THEME_SHELL = 'layouts/base.twig';

    /**
     * Back-compat alias: `templates/base.twig` extends {@see PUBLIC_ROOT}.
     */
    public const LEGACY_BASE_ALIAS = 'base.twig';
}
