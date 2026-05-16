<?php

declare(strict_types=1);

namespace MailingListPlugin;

final class Slugger
{
    public static function fromName(string $name): string
    {
        $s = strtolower(trim($name));
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
        $s = trim($s, '-');

        return $s !== '' ? $s : 'list';
    }

    public static function isValid(string $slug): bool
    {
        return preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) === 1;
    }

    public static function ensureUnique(ListRepository $repo, string $base, ?int $exceptId = null): string
    {
        $slug = $base;
        $n = 2;
        while ($repo->slugTaken($slug, $exceptId)) {
            $slug = $base . '-' . $n;
            ++$n;
        }

        return $slug;
    }
}
