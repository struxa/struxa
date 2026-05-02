<?php

declare(strict_types=1);

namespace App\Plugin;

/**
 * Safe JSON parsing with depth limit.
 */
final class PluginManifestParser
{
    private const MAX_DEPTH = 32;

    /**
     * @return array{ok: true, manifest: PluginManifest}|array{ok: false, error: string}
     */
    public function parseFile(string $path, string $directorySlug): array
    {
        if (!is_readable($path)) {
            return ['ok' => false, 'error' => 'plugin.json is not readable'];
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            return ['ok' => false, 'error' => 'Could not read plugin.json'];
        }

        try {
            $data = json_decode($raw, true, self::MAX_DEPTH, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return ['ok' => false, 'error' => 'Invalid JSON: ' . $e->getMessage()];
        }

        if (!is_array($data)) {
            return ['ok' => false, 'error' => 'plugin.json must be a JSON object'];
        }

        $manifest = PluginManifest::fromArray($data, $directorySlug);
        if ($manifest->name === '') {
            return ['ok' => false, 'error' => 'Manifest requires "name"'];
        }
        if ($manifest->slug === '' || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $manifest->slug)) {
            return ['ok' => false, 'error' => 'Manifest "slug" must be a non-empty kebab-case identifier'];
        }
        if ($manifest->slug !== $directorySlug) {
            return ['ok' => false, 'error' => 'Manifest slug "' . $manifest->slug . '" must match directory name "' . $directorySlug . '"'];
        }
        if ($manifest->version === '') {
            return ['ok' => false, 'error' => 'Manifest requires "version"'];
        }

        return ['ok' => true, 'manifest' => $manifest];
    }
}
