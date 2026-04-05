<?php

declare(strict_types=1);

namespace App\Filesystem;

/**
 * Deletes a directory tree only if it is a strict descendant of a trusted parent (symlink-safe via realpath).
 */
final class SafeDirectoryRemoval
{
    /**
     * @return string|null Error message, or null on success
     */
    public static function removeIfInside(string $absoluteDir, string $absoluteParent): ?string
    {
        $parentReal = realpath($absoluteParent);
        if ($parentReal === false || !is_dir($parentReal)) {
            return 'Parent path is not a directory.';
        }

        $dirReal = realpath($absoluteDir);
        if ($dirReal === false || !is_dir($dirReal)) {
            return 'Directory not found.';
        }

        $parentNorm = rtrim($parentReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if ($dirReal === $parentReal || !str_starts_with($dirReal . DIRECTORY_SEPARATOR, $parentNorm)) {
            return 'Refusing to delete outside the allowed root.';
        }

        self::deleteTree($dirReal);

        return null;
    }

    private static function deleteTree(string $dir): void
    {
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $name;
            if (is_dir($path) && !is_link($path)) {
                self::deleteTree($path);
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
