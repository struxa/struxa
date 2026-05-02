<?php

declare(strict_types=1);

namespace App\Cache;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Stable cache keys for public page caching (scheme, host, port, path, query).
 */
final class PublicPageCacheKey
{
    /**
     * Collapse duplicate slashes, ensure leading slash, drop trailing slash except root.
     */
    public static function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        if ($path === '' || $path === '/') {
            return '/';
        }
        $p = '/' . ltrim($path, '/');
        $collapsed = preg_replace('#/+#', '/', $p);
        $p = is_string($collapsed) ? $collapsed : $p;
        if ($p !== '/' && str_ends_with($p, '/')) {
            $p = rtrim($p, '/') ?: '/';
        }

        return $p;
    }

    /**
     * Canonical query string: sorted keys (recursive), RFC3986 encoding.
     *
     * @param array<string, mixed> $queryParams
     */
    public static function canonicalQuery(array $queryParams): string
    {
        if ($queryParams === []) {
            return '';
        }
        $params = $queryParams;
        self::ksortRecursive($params);

        return http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @param array<string, mixed> $arr
     */
    private static function ksortRecursive(array &$arr): void
    {
        ksort($arr);
        foreach ($arr as &$v) {
            if (is_array($v)) {
                self::ksortRecursive($v);
            }
        }
    }

    public static function build(ServerRequestInterface $request, string $version, string $storefrontThemeSlug = ''): string
    {
        $uri = $request->getUri();
        $scheme = strtolower($uri->getScheme() !== '' ? $uri->getScheme() : 'http');
        $host = strtolower($uri->getHost());
        $port = $uri->getPort();
        $portSeg = '';
        if ($port !== null) {
            $def = $scheme === 'https' ? 443 : 80;
            if ($port !== $def) {
                $portSeg = ':' . $port;
            }
        }
        $path = self::normalizePath($uri->getPath());
        $q = self::canonicalQuery($request->getQueryParams());
        $themeSeg = strtolower(trim($storefrontThemeSlug));
        if ($themeSeg === '') {
            $themeSeg = '_';
        }

        return $version . '|' . $scheme . '|' . $host . $portSeg . '|' . $path . '|' . $q . '|theme:' . $themeSeg;
    }
}
