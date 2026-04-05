<?php

declare(strict_types=1);

namespace App\Taxonomy;

use App\Content\ContentSlugger;

final class TaxonomyTermSlugger
{
    public static function ensureUnique(TaxonomyTermRepository $repo, int $taxonomyId, string $base, ?int $exceptId = null): string
    {
        $slug = $base;
        $n = 2;
        while ($repo->slugExists($taxonomyId, $slug, $exceptId)) {
            $slug = $base . '-' . $n;
            ++$n;
        }

        return $slug;
    }

    public static function slugify(string $name): string
    {
        return ContentSlugger::slugify($name);
    }
}
