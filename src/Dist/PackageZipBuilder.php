<?php

declare(strict_types=1);

namespace App\Dist;

use ZipArchive;

/**
 * Builds plugins/{slug}.zip under struxa-dist/zips from a directory on disk.
 */
final class PackageZipBuilder
{
    public function buildPluginZip(string $packageRoot, string $slug, string $zipsDir): ?string
    {
        if (!ZipExtension::isAvailable()) {
            return ZipExtension::requiredError();
        }
        if (!is_dir($packageRoot)) {
            return 'Plugin directory not found.';
        }
        if (!is_file($packageRoot . '/plugin.json')) {
            return 'plugin.json is missing in the plugin directory.';
        }
        if (!is_dir($zipsDir) && !@mkdir($zipsDir, 0755, true)) {
            return 'Could not create zips directory.';
        }

        $dest = rtrim($zipsDir, '/\\') . '/' . $slug . '.zip';
        if (is_file($dest)) {
            @unlink($dest);
        }

        $zip = new ZipArchive();
        if ($zip->open($dest, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return 'Could not create distribution ZIP.';
        }

        $rootLen = strlen($packageRoot) + 1;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($packageRoot, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }
            $path = $file->getPathname();
            $rel = substr($path, $rootLen);
            if ($rel === false || str_contains($rel, '..')) {
                continue;
            }
            if (str_starts_with($rel, 'vendor/') || str_starts_with($rel, 'node_modules/') || str_contains($rel, '.git/')) {
                continue;
            }
            $zip->addFile($path, str_replace('\\', '/', $rel));
        }
        $zip->close();

        return is_file($dest) ? null : 'ZIP file was not created.';
    }
}
