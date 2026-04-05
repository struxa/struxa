<?php

declare(strict_types=1);

namespace App\Cache;

/**
 * Optional Redis driver (ext-redis). Falls back to no-op clear if connection fails.
 */
final class RedisCache implements CacheInterface
{
    /** @var \Redis|null */
    private $redis;

    public function __construct(
        private readonly string $keyPrefix,
        ?\Redis $redis = null,
    ) {
        $this->redis = $redis;
    }

    public static function tryFromEnv(string $keyPrefix = 'struxa:'): ?self
    {
        if (!extension_loaded('redis') || !class_exists(\Redis::class)) {
            return null;
        }
        $url = trim((string) ($_ENV['STRUXA_REDIS_URL'] ?? ''));
        if ($url === '') {
            return null;
        }
        try {
            $r = new \Redis();
            if (str_starts_with($url, 'redis://') || str_starts_with($url, 'rediss://')) {
                $parts = parse_url($url);
                if ($parts === false || empty($parts['host'])) {
                    return null;
                }
                $host = (string) $parts['host'];
                $port = isset($parts['port']) ? (int) $parts['port'] : 6379;
                $timeout = 1.5;
                if (!$r->connect($host, $port, $timeout)) {
                    return null;
                }
                if (!empty($parts['pass'])) {
                    $r->auth((string) $parts['pass']);
                }
                $db = 0;
                if (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/') {
                    $db = max(0, (int) ltrim((string) $parts['path'], '/'));
                }
                if ($db > 0) {
                    $r->select($db);
                }
            } else {
                if (!$r->connect($url, 6379, 1.5)) {
                    return null;
                }
            }

            return new self($keyPrefix, $r);
        } catch (\Throwable) {
            return null;
        }
    }

    public function get(string $key): mixed
    {
        if ($this->redis === null) {
            return null;
        }
        $raw = $this->redis->get($this->keyPrefix . $key);
        if ($raw === false || $raw === null) {
            return null;
        }
        /** @var mixed $decoded */
        $decoded = json_decode((string) $raw, true);

        return $decoded;
    }

    public function set(string $key, mixed $value, ?int $ttlSeconds = null): void
    {
        if ($this->redis === null) {
            return;
        }
        $payload = json_encode($value, JSON_THROW_ON_ERROR);
        $k = $this->keyPrefix . $key;
        if ($ttlSeconds !== null && $ttlSeconds > 0) {
            $this->redis->setex($k, $ttlSeconds, $payload);
        } else {
            $this->redis->set($k, $payload);
        }
    }

    public function delete(string $key): void
    {
        if ($this->redis === null) {
            return;
        }
        $this->redis->del($this->keyPrefix . $key);
    }

    public function clear(): void
    {
        if ($this->redis === null) {
            return;
        }
        $keys = $this->redis->keys($this->keyPrefix . '*');
        if (is_array($keys) && $keys !== []) {
            $this->redis->del($keys);
        }
    }
}
