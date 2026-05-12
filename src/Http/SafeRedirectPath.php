<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Validates a user-supplied {@code next} path for use after authentication.
 *
 * Rejects anything that could land outside the same origin or smuggle through a Location header.
 * Returns {@code $fallback} for any input that is not a same-origin path.
 */
final class SafeRedirectPath
{
    public static function afterLogin(?string $next, string $fallback = '/'): string
    {
        if ($next === null || $next === '') {
            return $fallback;
        }

        $next = rawurldecode(trim($next));
        if ($next === '') {
            return $fallback;
        }

        // 1. Control chars and backslashes break Location header parsing or get normalized to '/'
        //    by browsers (\\evil.com → //evil.com → open redirect).
        if (preg_match('/[\x00-\x1F\x7F\\\\]/u', $next) === 1) {
            return $fallback;
        }

        // 2. Must be an absolute path that does NOT introduce a host. Disallow:
        //    "//evil.com..." (protocol-relative), "/\\evil.com" (handled above), and any path
        //    that includes a scheme or authority component.
        if (!str_starts_with($next, '/') || str_starts_with($next, '//')) {
            return $fallback;
        }

        // 3. A leading "/" followed by "/" later in the URL is fine (e.g. /foo/bar), but the
        //    *second* character must not be another path separator or auth marker that could
        //    be parsed by edge cases (e.g. "/@evil.com" is OK as a path, "/.." is not).
        if (preg_match('/^\/\.\.($|\/)/', $next) === 1) {
            return $fallback;
        }

        // 4. Disallow schemes embedded in the path (e.g. "/javascript:alert(1)"). Anything before
        //    a literal ':' that isn't otherwise a normal path segment should be rejected if the
        //    colon precedes a '//' (a real scheme separator).
        if (preg_match('#^/[^/]*:\\s*/{2}#i', $next) === 1) {
            return $fallback;
        }

        return $next;
    }
}
