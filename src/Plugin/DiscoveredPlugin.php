<?php

declare(strict_types=1);

namespace App\Plugin;

final class DiscoveredPlugin
{
    public function __construct(
        public readonly string $directorySlug,
        public readonly string $rootPath,
        public readonly PluginManifest $manifest,
    ) {
    }
}
