<?php

declare(strict_types=1);

namespace App;

/**
 * Installed CMS semantic version (semver). Single source of truth for:
 * plugin `requires_cms_version`, the Twig global `cms_version`, exports, and API metadata.
 *
 * Bump when you ship a release customers or plugins should target.
 */
final class CmsVersion
{
    public const CURRENT = '1.1.99';
}
