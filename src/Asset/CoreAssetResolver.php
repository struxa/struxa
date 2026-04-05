<?php

declare(strict_types=1);

namespace App\Asset;

/**
 * Root-relative URLs for files under public/, with optional .min.css/.min.js and ?v=mtime.
 */
final class CoreAssetResolver
{
    public function __construct(
        private readonly string $publicRoot,
        private readonly bool $preferMinified,
    ) {
    }

    public function url(string $path): string
    {
        $rel = ltrim(str_replace('\\', '/', $path), '/');
        if ($rel === '' || str_contains($rel, '..')) {
            return '';
        }

        if ($this->preferMinified && preg_match('/\.(css|js)$/i', $rel) === 1) {
            $min = preg_replace('/\.(css|js)$/i', '.min.$1', $rel, 1);
            if (is_string($min) && $min !== $rel && is_file($this->publicRoot . '/' . $min)) {
                $rel = $min;
            }
        }

        $full = $this->publicRoot . '/' . $rel;
        $v = 0;
        if (is_file($full)) {
            $mt = @filemtime($full);
            $v = $mt !== false ? (int) $mt : 0;
        }
        $url = '/' . $rel;

        return $v > 0 ? $url . '?v=' . $v : $url;
    }
}
