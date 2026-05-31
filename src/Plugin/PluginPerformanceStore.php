<?php

declare(strict_types=1);

namespace App\Plugin;

/**
 * Persists plugin performance snapshots under storage/plugin-performance.json.
 */
final class PluginPerformanceStore
{
    private const MAX_SLOW_HOOKS = 10;

    public function __construct(private readonly string $projectRoot)
    {
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        $data = $this->read();
        $plugins = $data['plugins'] ?? [];

        return is_array($plugins) ? $plugins : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function forSlug(string $slug): ?array
    {
        $row = $this->all()[$slug] ?? null;

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, mixed> $patch
     */
    public function merge(string $slug, array $patch): void
    {
        $data = $this->read();
        $plugins = $data['plugins'] ?? [];
        if (!is_array($plugins)) {
            $plugins = [];
        }

        $existing = $plugins[$slug] ?? [];
        if (!is_array($existing)) {
            $existing = [];
        }

        if (isset($patch['slow_hooks']) && is_array($patch['slow_hooks'])) {
            $prev = $existing['slow_hooks'] ?? [];
            if (!is_array($prev)) {
                $prev = [];
            }
            $patch['slow_hooks'] = array_slice(
                array_merge($patch['slow_hooks'], $prev),
                0,
                self::MAX_SLOW_HOOKS,
            );
        }

        $plugins[$slug] = array_merge($existing, $patch);
        $data['plugins'] = $plugins;
        $data['updated_at'] = gmdate('c');
        $this->write($data);
    }

    /**
     * @return array<string, mixed>
     */
    private function read(): array
    {
        $path = $this->path();
        if (!is_file($path)) {
            return ['plugins' => []];
        }
        $raw = file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return ['plugins' => []];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : ['plugins' => []];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function write(array $data): void
    {
        $dir = $this->projectRoot . '/storage';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $path = $this->path();
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }
        file_put_contents($path, $json . "\n", LOCK_EX);
    }

    private function path(): string
    {
        return $this->projectRoot . '/storage/plugin-performance.json';
    }
}
