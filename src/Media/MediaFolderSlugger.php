<?php

declare(strict_types=1);

namespace App\Media;

use App\Content\ContentSlugger;

final class MediaFolderSlugger
{
    public static function slugify(string $name): string
    {
        return ContentSlugger::slugify($name);
    }

    public static function ensureUnique(MediaFolderRepository $repo, ?int $parentId, string $base, ?int $exceptId = null): string
    {
        $slug = $base;
        $n = 2;
        while ($repo->slugExistsAmongSiblings($parentId, $slug, $exceptId)) {
            $slug = $base . '-' . $n;
            ++$n;
        }

        return $slug;
    }
}
