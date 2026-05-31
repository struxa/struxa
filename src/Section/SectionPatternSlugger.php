<?php

declare(strict_types=1);

namespace App\Section;

final class SectionPatternSlugger
{
    public static function slugify(string $name): string
    {
        $s = strtolower(trim($name));
        if (function_exists('iconv')) {
            $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
            if ($t !== false) {
                $s = $t;
            }
        }
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
        $s = trim($s, '-');

        return $s !== '' ? $s : 'pattern';
    }

    public static function ensureUnique(SectionPatternRepository $repo, string $base, ?int $exceptId = null): string
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
