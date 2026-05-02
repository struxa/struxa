<?php

declare(strict_types=1);

namespace App\Content;

final class ContentSlugger
{
    public static function slugify(string $title): string
    {
        $s = strtolower(trim($title));
        if (function_exists('iconv')) {
            $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
            if ($t !== false) {
                $s = $t;
            }
        }
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
        $s = trim($s, '-');

        return $s !== '' ? $s : 'entry';
    }

    public static function ensureUniqueEntry(ContentEntryRepository $repo, int $typeId, string $base, ?int $exceptId = null): string
    {
        $slug = $base;
        $n = 2;
        while ($repo->slugExists($typeId, $slug, $exceptId)) {
            $slug = $base . '-' . $n;
            ++$n;
        }

        return $slug;
    }

    public static function ensureUniqueType(ContentTypeRepository $repo, string $base, ?int $exceptId = null): string
    {
        $slug = $base;
        $n = 2;
        while ($repo->slugExists($slug, $exceptId)) {
            $slug = $base . '-' . $n;
            ++$n;
        }

        return $slug;
    }
}
