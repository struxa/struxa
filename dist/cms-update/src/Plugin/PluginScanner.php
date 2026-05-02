<?php

declare(strict_types=1);

namespace App\Plugin;

/**
 * Filesystem discovery under /plugins/{slug}/.
 */
final class PluginScanner
{
    /** @var list<DiscoveredPlugin>|null */
    private ?array $discoverCache = null;

    public function __construct(
        private readonly string $projectRoot,
        private readonly PluginManifestParser $parser = new PluginManifestParser(),
    ) {
    }

    /**
     * @return list<DiscoveredPlugin>
     */
    public function discover(): array
    {
        if ($this->discoverCache !== null) {
            return $this->discoverCache;
        }

        $dir = $this->projectRoot . '/plugins';
        if (!is_dir($dir)) {
            $this->discoverCache = [];

            return $this->discoverCache;
        }

        $out = [];
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $name)) {
                continue;
            }
            $path = $dir . '/' . $name;
            if (!is_dir($path)) {
                continue;
            }
            $manifestPath = $path . '/plugin.json';
            if (!is_file($manifestPath)) {
                continue;
            }
            $src = $path . '/src';
            if (!is_dir($src)) {
                continue;
            }

            $parsed = $this->parser->parseFile($manifestPath, $name);
            if (!$parsed['ok']) {
                continue;
            }

            $out[] = new DiscoveredPlugin($name, $path, $parsed['manifest']);
        }

        usort($out, static fn (DiscoveredPlugin $a, DiscoveredPlugin $b): int => strcmp($a->manifest->name, $b->manifest->name));

        $this->discoverCache = $out;

        return $this->discoverCache;
    }

    public function findBySlug(string $slug): ?DiscoveredPlugin
    {
        foreach ($this->discover() as $p) {
            if ($p->directorySlug === $slug) {
                return $p;
            }
        }

        return null;
    }

    public function clearDiscoverCache(): void
    {
        $this->discoverCache = null;
    }
}
