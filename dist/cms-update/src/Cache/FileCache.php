<?php

declare(strict_types=1);

namespace App\Cache;

/**
 * File-backed cache under a dedicated subdirectory (namespace).
 */
final class FileCache implements CacheInterface
{
    public function __construct(
        private readonly string $basePath,
        private readonly string $namespace = 'default',
    ) {
    }

    public function getBasePath(): string
    {
        return $this->basePath;
    }

    public function namespacePath(): string
    {
        return $this->directory();
    }

    public function withNamespace(string $namespace): self
    {
        return new self($this->basePath, $namespace);
    }

    public function get(string $key): mixed
    {
        $path = $this->pathFor($key);
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        /** @var array{exp: int|null, v: mixed}|null $wrap */
        $wrap = json_decode($raw, true);
        if (!is_array($wrap) || !array_key_exists('v', $wrap)) {
            return null;
        }
        $exp = $wrap['exp'] ?? null;
        if ($exp !== null && is_int($exp) && $exp > 0 && $exp < time()) {
            @unlink($path);

            return null;
        }

        return $wrap['v'];
    }

    public function set(string $key, mixed $value, ?int $ttlSeconds = null): void
    {
        $dir = $this->directory();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }
        $exp = null;
        if ($ttlSeconds !== null && $ttlSeconds > 0) {
            $exp = time() + $ttlSeconds;
        }
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
            $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
        }
        $payload = json_encode(['exp' => $exp, 'v' => $value], $flags | JSON_THROW_ON_ERROR);
        $path = $this->pathFor($key);
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, $payload, LOCK_EX) === false) {
            return;
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
        }
    }

    public function delete(string $key): void
    {
        $p = $this->pathFor($key);
        if (is_file($p)) {
            @unlink($p);
        }
    }

    public function clear(): void
    {
        $dir = $this->directory();
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*.json') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    private function directory(): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', $this->namespace) ?: 'default';

        return rtrim($this->basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $safe;
    }

    private function pathFor(string $key): string
    {
        $hash = hash('sha256', $key);

        return $this->directory() . DIRECTORY_SEPARATOR . $hash . '.json';
    }
}
