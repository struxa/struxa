<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Simple fixed-window rate limiting using the filesystem (works across PHP-FPM workers).
 */
final class FileRateLimiter
{
    public function __construct(private readonly string $storageDir)
    {
    }

    /**
     * @return true if under limit (and increment counter), false if exceeded
     */
    public function hit(string $bucket, string $clientKey, int $maxHits, int $windowSeconds): bool
    {
        if ($maxHits < 1 || $windowSeconds < 1) {
            return true;
        }
        if (!is_dir($this->storageDir) && !@mkdir($this->storageDir, 0775, true) && !is_dir($this->storageDir)) {
            return true;
        }

        $window = (int) (time() / $windowSeconds);
        $hash = hash('sha256', $bucket . "\0" . $clientKey . "\0" . (string) $window);
        $path = $this->storageDir . '/' . $hash . '.json';

        $count = 0;
        if (is_file($path)) {
            $raw = @file_get_contents($path);
            if ($raw !== false) {
                try {
                    /** @var mixed $j */
                    $j = json_decode($raw, true, 2, JSON_THROW_ON_ERROR);
                    if (is_array($j) && isset($j['c']) && is_int($j['c']) && ($j['w'] ?? null) === $window) {
                        $count = $j['c'];
                    }
                } catch (\JsonException) {
                    $count = 0;
                }
            }
        }

        if ($count >= $maxHits) {
            return false;
        }

        $payload = json_encode(['w' => $window, 'c' => $count + 1], JSON_THROW_ON_ERROR);
        @file_put_contents($path, $payload, LOCK_EX);

        return true;
    }

    public static function clientIp(\Psr\Http\Message\ServerRequestInterface $request): string
    {
        return \App\Http\ClientIp::fromRequest($request);
    }
}
