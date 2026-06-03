<?php

declare(strict_types=1);

namespace App\Dist;

/**
 * Writes struxa-dist/repo.json for small catalogs (v1) or sharded v2 when large.
 * Always writes repo-summary.json for cheap theme/plugin counts.
 */
final class StruxaDistCatalogWriter
{
    public const PLUGIN_SHARD_SIZE = 100;

    public const DESCRIPTION_MAX_LENGTH = 240;

    /** Stay monolithic v1 below this encoded size (bytes). */
    private const V1_MAX_BYTES = 450_000;

    /**
     * @param list<array<string, mixed>> $themes
     * @param list<array<string, mixed>> $plugins
     *
     * @return array{
     *   ok: true,
     *   catalog_version: int,
     *   themes: int,
     *   plugins: int,
     *   sharded: bool
     * }|array{ok: false, error: string}
     */
    public function write(string $distRoot, array $themes, array $plugins): array
    {
        $distRoot = rtrim($distRoot, '/\\');
        if (!is_dir($distRoot) && !@mkdir($distRoot, 0755, true)) {
            return ['ok' => false, 'error' => 'Could not create dist directory: ' . $distRoot];
        }

        $generatedAt = gmdate('c');
        $themes = $this->compactEntries($themes);
        $plugins = $this->compactEntries($plugins);

        $v1Catalog = [
            'catalog_version' => 1,
            'generated_at' => $generatedAt,
            'themes' => $themes,
            'plugins' => $plugins,
        ];
        $v1Json = $this->encode($v1Catalog);
        if ($v1Json === null) {
            return ['ok' => false, 'error' => 'Failed to encode catalog JSON.'];
        }

        $sharded = strlen($v1Json) > self::V1_MAX_BYTES;
        $catalogVersion = 1;
        $repoCatalog = $v1Catalog;

        if ($sharded) {
            $shardResult = $this->writePluginShards($distRoot, $plugins);
            if (!$shardResult['ok']) {
                return $shardResult;
            }
            $catalogVersion = 2;
            $repoCatalog = [
                'catalog_version' => 2,
                'generated_at' => $generatedAt,
                'totals' => [
                    'themes' => count($themes),
                    'plugins' => count($plugins),
                ],
                'themes' => $themes,
                'plugins' => [
                    'shards' => $shardResult['shards'],
                    'page_size' => self::PLUGIN_SHARD_SIZE,
                ],
            ];
            $repoJson = $this->encode($repoCatalog);
            if ($repoJson === null) {
                return ['ok' => false, 'error' => 'Failed to encode sharded catalog index.'];
            }
        } else {
            $repoJson = $v1Json;
        }

        if (file_put_contents($distRoot . '/repo.json', $repoJson . "\n") === false) {
            return ['ok' => false, 'error' => 'Could not write repo.json.'];
        }

        $summary = [
            'catalog_version' => $catalogVersion,
            'generated_at' => $generatedAt,
            'themes_count' => count($themes),
            'plugins_count' => count($plugins),
            'repo_index' => 'repo.json',
            'sharded' => $sharded,
        ];
        $summaryJson = $this->encode($summary);
        if ($summaryJson === null || file_put_contents($distRoot . '/repo-summary.json', $summaryJson . "\n") === false) {
            return ['ok' => false, 'error' => 'Could not write repo-summary.json.'];
        }

        return [
            'ok' => true,
            'catalog_version' => $catalogVersion,
            'themes' => count($themes),
            'plugins' => count($plugins),
            'sharded' => $sharded,
        ];
    }

    /**
     * @param list<array<string, mixed>> $plugins
     *
     * @return array{ok: true, shards: list<string>}|array{ok: false, error: string}
     */
    private function writePluginShards(string $distRoot, array $plugins): array
    {
        $shardDir = $distRoot . '/catalog';
        if (is_dir($shardDir)) {
            foreach (glob($shardDir . '/plugins-*.json') ?: [] as $old) {
                if (is_file($old)) {
                    @unlink($old);
                }
            }
        } elseif (!@mkdir($shardDir, 0755, true)) {
            return ['ok' => false, 'error' => 'Could not create catalog shard directory.'];
        }

        $shards = [];
        $chunks = array_chunk($plugins, self::PLUGIN_SHARD_SIZE);
        foreach ($chunks as $i => $chunk) {
            $name = sprintf('catalog/plugins-%03d.json', $i + 1);
            $payload = $this->encode(['plugins' => $chunk]);
            if ($payload === null) {
                return ['ok' => false, 'error' => 'Failed to encode plugin shard ' . ($i + 1) . '.'];
            }
            if (file_put_contents($distRoot . '/' . str_replace('/', DIRECTORY_SEPARATOR, $name), $payload . "\n") === false) {
                return ['ok' => false, 'error' => 'Could not write ' . $name . '.'];
            }
            $shards[] = $name;
        }

        return ['ok' => true, 'shards' => $shards];
    }

    /**
     * @param list<array<string, mixed>> $entries
     *
     * @return list<array<string, mixed>>
     */
    private function compactEntries(array $entries): array
    {
        $out = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $row = $entry;
            if (isset($row['description']) && is_string($row['description'])) {
                $desc = trim($row['description']);
                if (mb_strlen($desc) > self::DESCRIPTION_MAX_LENGTH) {
                    $row['description'] = mb_substr($desc, 0, self::DESCRIPTION_MAX_LENGTH - 1) . '…';
                }
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function encode(array $data): ?string
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $json === false ? null : $json;
    }
}
