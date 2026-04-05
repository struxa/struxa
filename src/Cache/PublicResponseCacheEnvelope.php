<?php

declare(strict_types=1);

namespace App\Cache;

/**
 * Wraps stored public HTTP payloads with a logical cache key for admin inspection.
 * Legacy entries (bare status/headers/body) remain readable.
 */
final class PublicResponseCacheEnvelope
{
    /**
     * @param array{status: int, headers: array<string, list<string>>, body: string} $responsePayload
     *
     * @return array{response: array{status: int, headers: array<string, list<string>>, body: string}, meta: array{cache_key: string, stored_at: int}}
     */
    public static function wrap(string $cacheKey, array $responsePayload): array
    {
        return [
            'response' => $responsePayload,
            'meta' => [
                'cache_key' => $cacheKey,
                'stored_at' => time(),
            ],
        ];
    }

    /**
     * @return array{status: int, headers: array<string, list<string>>, body: string}|null
     */
    public static function responsePayload(mixed $v): ?array
    {
        if (!is_array($v)) {
            return null;
        }
        if (isset($v['response']) && is_array($v['response'])) {
            $r = $v['response'];

            return isset($r['status'], $r['body']) && is_int($r['status']) ? $r : null;
        }
        if (isset($v['status'], $v['body']) && is_int($v['status'])) {
            return $v;
        }

        return null;
    }

    /**
     * @return array{cache_key?: string, stored_at?: int}|null
     */
    public static function meta(mixed $v): ?array
    {
        if (!is_array($v) || !isset($v['meta']) || !is_array($v['meta'])) {
            return null;
        }

        return $v['meta'];
    }
}
