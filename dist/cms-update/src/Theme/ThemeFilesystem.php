<?php

declare(strict_types=1);

namespace App\Theme;

/**
 * Path containment checks (symlink-safe when used with realpath results).
 */
final class ThemeFilesystem
{
    /**
     * True if $fileReal is a file/directory inside $directoryReal (not the directory root itself as the "file").
     */
    public static function pathIsInsideDirectory(string $pathReal, string $directoryReal): bool
    {
        if ($pathReal === '' || $directoryReal === '') {
            return false;
        }

        $dir = rtrim($directoryReal, '/\\') . DIRECTORY_SEPARATOR;

        return str_starts_with($pathReal, $dir);
    }

    /**
     * @return list<string> non-empty segments, rejects any "." or ".." or empty segment
     */
    public static function safeRelativePathSegments(string $path): array
    {
        $path = str_replace('\\', '/', $path);
        $path = ltrim($path, '/');
        if ($path === '' || str_contains($path, '..')) {
            return [];
        }

        $parts = explode('/', $path);
        $out = [];
        foreach ($parts as $p) {
            if ($p === '' || $p === '.' || $p === '..') {
                return [];
            }
            $out[] = $p;
        }

        return $out;
    }
}
