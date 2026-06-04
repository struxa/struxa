<?php

declare(strict_types=1);

namespace App\Dist;

use ZipArchive;

/**
 * Whether the PHP zip extension (ZipArchive) is available for catalog ZIP builds.
 */
final class ZipExtension
{
    public static function isAvailable(): bool
    {
        if (extension_loaded('zip')) {
            return true;
        }

        return class_exists(\ZipArchive::class, false);
    }

    /**
     * What the current PHP process (web or CLI) actually sees — use this when cPanel shows zip enabled
     * but catalog publish still fails (often a different PHP version for FPM vs MultiPHP UI).
     *
     * @return array{
     *   php_version: string,
     *   sapi: string,
     *   ini: string,
     *   zip_loaded: bool,
     *   ziparchive_class: bool,
     *   available: bool,
     *   write_probe: string|null,
     *   ext_dir: string
     * }
     */
    public static function diagnostics(): array
    {
        return [
            'php_version' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'ini' => (string) (php_ini_loaded_file() ?: ''),
            'zip_loaded' => extension_loaded('zip'),
            'ziparchive_class' => class_exists(ZipArchive::class, false),
            'available' => self::isAvailable(),
            'write_probe' => self::writeProbe(),
            'ext_dir' => (string) (ini_get('extension_dir') ?: ''),
        ];
    }

    /** @return null|string Error message when probe fails */
    public static function writeProbe(): ?string
    {
        if (!self::isAvailable()) {
            return 'ZipArchive is not available in this PHP process.';
        }
        $path = sys_get_temp_dir() . '/struxa-zip-probe-' . bin2hex(random_bytes(4)) . '.zip';
        $zip = new ZipArchive();
        $code = $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($code !== true) {
            return 'ZipArchive::open failed (code ' . (string) $code . ').';
        }
        if (!$zip->addFromString('probe.txt', 'ok')) {
            $zip->close();
            @unlink($path);

            return 'ZipArchive::addFromString failed.';
        }
        $zip->close();
        if (!is_file($path)) {
            return 'Probe ZIP was not written to disk.';
        }
        @unlink($path);

        return null;
    }

    /** @return null|string Error when the directory is missing and cannot be created or is not writable */
    public static function probeWritableDirectory(string $dir): ?string
    {
        if (is_dir($dir)) {
            return is_writable($dir) ? null : 'Directory is not writable: ' . $dir;
        }
        if (!@mkdir($dir, 0755, true)) {
            return 'Could not create directory: ' . $dir;
        }
        if (!is_writable($dir)) {
            return 'Directory was created but is not writable: ' . $dir;
        }

        return null;
    }

    public static function requiredError(): string
    {
        $d = self::diagnostics();
        $ini = $d['ini'] !== '' ? $d['ini'] : '(unknown php.ini)';
        $probe = $d['write_probe'] ?? 'OK';

        return sprintf(
            'PHP zip extension (ZipArchive) is required. This HTTP request runs PHP %s (%s): extension zip=%s, ZipArchive class=%s, write probe=%s, php.ini=%s. '
            . 'cPanel often enables zip for a different PHP version than the one serving /admin — match MultiPHP to this version and restart PHP-FPM.',
            $d['php_version'],
            $d['sapi'],
            $d['zip_loaded'] ? 'loaded' : 'not loaded',
            $d['ziparchive_class'] ? 'yes' : 'no',
            $probe,
            $ini,
        );
    }
}
