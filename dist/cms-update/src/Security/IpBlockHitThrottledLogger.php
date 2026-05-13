<?php

declare(strict_types=1);

namespace App\Security;

use PDO;
use PDOException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Batches blocked-request counts per client IP on disk (flock), then flushes to
 * cms_ip_block_hit_buckets with INSERT ... ON DUPLICATE KEY UPDATE.
 *
 * Anti-spam: at most ~1 flush per IP per {@see FLUSH_INTERVAL_SEC} (or sooner if
 * {@see FLUSH_MAX_PENDING} is reached), plus a global cap on flush operations per minute.
 * A shutdown flush catches leftover counts (e.g. a single blocked request).
 */
final class IpBlockHitThrottledLogger
{
    private const FLUSH_INTERVAL_SEC = 15;

    private const FLUSH_MAX_PENDING = 50;

    private const GLOBAL_FLUSHES_PER_MINUTE = 400;

    public function __construct(
        private readonly IpBlockHitBucketRepository $buckets,
        private readonly string $pendingDir,
        private readonly string $budgetDir,
    ) {
    }

    public static function createDefault(PDO $pdo, string $projectRoot): self
    {
        $root = rtrim($projectRoot, DIRECTORY_SEPARATOR);

        return new self(
            new IpBlockHitBucketRepository($pdo),
            $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'ip_block_hit_pending',
            $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'ip_block_hit_budget',
        );
    }

    public function recordBlockedHit(ServerRequestInterface $request, string $clientIp): void
    {
        if ($clientIp === '') {
            return;
        }

        $path = $request->getUri()->getPath();
        $query = $request->getUri()->getQuery();
        if ($query !== '') {
            $path .= '?' . (strlen($query) > 220 ? substr($query, 0, 220) . '…' : $query);
        }
        $ua = $request->getHeaderLine('User-Agent');

        $ip = $clientIp;
        register_shutdown_function(function () use ($ip): void {
            $this->flushPendingForIpShutdown($ip);
        });

        $this->accumulateAndMaybeFlush($ip, $path, $ua, false);
    }

    private function flushPendingForIpShutdown(string $clientIp): void
    {
        $this->accumulateAndMaybeFlush($clientIp, '', '', true);
    }

    private function accumulateAndMaybeFlush(string $clientIp, string $path, string $ua, bool $shutdownOnly): void
    {
        if (!is_dir($this->pendingDir) && !@mkdir($this->pendingDir, 0775, true) && !is_dir($this->pendingDir)) {
            return;
        }

        $file = $this->pendingDir . DIRECTORY_SEPARATOR . hash('sha256', $clientIp) . '.json';
        $fh = @fopen($file, 'c+');
        if ($fh === false) {
            return;
        }

        try {
            if (!flock($fh, LOCK_EX)) {
                return;
            }

            try {
                $raw = stream_get_contents($fh);
                /** @var array{c?: int|float, ps?: int|float, bh?: int|float, path?: string, ua?: string}|null $state */
                $state = null;
                if (is_string($raw) && $raw !== '') {
                    try {
                        $j = json_decode($raw, true, 10, JSON_THROW_ON_ERROR);
                        $state = is_array($j) ? $j : null;
                    } catch (\JsonException) {
                        $state = null;
                    }
                }

                $hour = intdiv(time(), 3600);
                $c = isset($state['c']) ? max(0, (int) $state['c']) : 0;
                $bh = isset($state['bh']) ? (int) $state['bh'] : $hour;
                $ps = isset($state['ps']) ? (int) $state['ps'] : 0;

                if ($shutdownOnly) {
                    if ($c > 0) {
                        $this->tryFlushLocked($clientIp, $bh, $c, (string) ($state['path'] ?? ''), (string) ($state['ua'] ?? ''));
                        $c = 0;
                        $bh = $hour;
                        $ps = 0;
                    }
                    $this->writeState($fh, $c, $ps, $bh, '', '');

                    return;
                }

                if ($bh !== $hour && $c > 0) {
                    $this->tryFlushLocked($clientIp, $bh, $c, (string) ($state['path'] ?? ''), (string) ($state['ua'] ?? ''));
                    $c = 0;
                    $bh = $hour;
                    $ps = 0;
                }

                ++$c;
                if ($c === 1) {
                    $ps = time();
                }
                $lastPath = $path !== '' ? $path : (string) ($state['path'] ?? '');
                $lastUa = $ua !== '' ? $ua : (string) ($state['ua'] ?? '');

                $shouldFlush = $c >= self::FLUSH_MAX_PENDING || (time() - $ps >= self::FLUSH_INTERVAL_SEC);
                if ($shouldFlush) {
                    $this->tryFlushLocked($clientIp, $bh, $c, $lastPath, $lastUa);
                    $c = 0;
                    $ps = time();
                }

                $this->writeState($fh, $c, $ps, $bh, $lastPath, $lastUa);
            } finally {
                flock($fh, LOCK_UN);
            }
        } finally {
            fclose($fh);
        }
    }

    /**
     * @param resource $fh
     */
    private function writeState($fh, int $c, int $ps, int $bh, string $path, string $ua): void
    {
        rewind($fh);
        ftruncate($fh, 0);
        $payload = json_encode([
            'c' => $c,
            'ps' => $ps,
            'bh' => $bh,
            'path' => $path,
            'ua' => $ua,
        ], JSON_THROW_ON_ERROR);
        fwrite($fh, $payload);
    }

    private function tryFlushLocked(string $clientIp, int $bucketHour, int $delta, string $path, string $ua): void
    {
        if ($delta < 1 || !$this->consumeGlobalFlushBudget()) {
            return;
        }
        try {
            $this->buckets->addHits($clientIp, $bucketHour, $delta, $path, $ua);
        } catch (PDOException) {
            // Never break the 403 response path.
        }
    }

    private function consumeGlobalFlushBudget(): bool
    {
        if (!is_dir($this->budgetDir) && !@mkdir($this->budgetDir, 0775, true) && !is_dir($this->budgetDir)) {
            return true;
        }
        $path = $this->budgetDir . DIRECTORY_SEPARATOR . 'minute.json';
        $fh = @fopen($path, 'c+');
        if ($fh === false) {
            return true;
        }
        try {
            if (!flock($fh, LOCK_EX)) {
                return false;
            }
            try {
                $minute = intdiv(time(), 60);
                $raw = stream_get_contents($fh);
                $n = 0;
                $m = $minute;
                if (is_string($raw) && $raw !== '') {
                    try {
                        $j = json_decode($raw, true, 5, JSON_THROW_ON_ERROR);
                        if (is_array($j)) {
                            $m = isset($j['m']) ? (int) $j['m'] : $minute;
                            $n = isset($j['n']) ? (int) $j['n'] : 0;
                        }
                    } catch (\JsonException) {
                        $n = 0;
                        $m = $minute;
                    }
                }
                if ($m !== $minute) {
                    $m = $minute;
                    $n = 0;
                }
                if ($n >= self::GLOBAL_FLUSHES_PER_MINUTE) {
                    return false;
                }
                ++$n;
                rewind($fh);
                ftruncate($fh, 0);
                fwrite($fh, json_encode(['m' => $m, 'n' => $n], JSON_THROW_ON_ERROR));

                return true;
            } finally {
                flock($fh, LOCK_UN);
            }
        } finally {
            fclose($fh);
        }
    }
}
