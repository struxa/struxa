<?php

declare(strict_types=1);

namespace App\Cache;

/**
 * Simple key/value cache with optional TTL (seconds).
 */
interface CacheInterface
{
    /**
     * @return mixed|null Null when missing or expired.
     */
    public function get(string $key): mixed;

    public function set(string $key, mixed $value, ?int $ttlSeconds = null): void;

    public function delete(string $key): void;

    /**
     * Remove all entries in this cache partition (namespace).
     */
    public function clear(): void;
}
