<?php

declare(strict_types=1);

namespace App\Http;

final class SafeRedirectPath
{
    public static function afterLogin(?string $next, string $fallback = '/'): string
    {
        if ($next === null || $next === '') {
            return $fallback;
        }

        $next = rawurldecode(trim($next));
        if ($next === '' || !str_starts_with($next, '/') || str_starts_with($next, '//')) {
            return $fallback;
        }

        return $next;
    }
}
