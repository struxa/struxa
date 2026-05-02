<?php

declare(strict_types=1);

namespace App\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Resolves the client IP for rate limiting and logging. Uses X-Forwarded-For / X-Real-IP only when
 * the immediate peer (REMOTE_ADDR) is listed in CMS_TRUSTED_PROXY_IPS (comma-separated IPs or IPv4 CIDR).
 */
final class ClientIp
{
    /**
     * Same as {@see fromRequest} using PHP superglobals (PHPAuth and other code paths without a PSR-7 request).
     *
     * @return non-empty-string
     */
    public static function fromSuperglobals(): string
    {
        /** @var array<string, mixed> $server */
        $server = $_SERVER;

        return self::fromServerParams($server);
    }

    /**
     * @param array<string, mixed> $server
     *
     * @return non-empty-string
     */
    public static function fromServerParams(array $server): string
    {
        $remote = isset($server['REMOTE_ADDR']) && is_string($server['REMOTE_ADDR']) ? trim($server['REMOTE_ADDR']) : '';
        if ($remote === '') {
            return '0.0.0.0';
        }

        $trusted = self::trustedEntries();
        if ($trusted === [] || !self::addressMatchesTrusted($remote, $trusted)) {
            return $remote;
        }

        $xff = '';
        if (isset($server['HTTP_X_FORWARDED_FOR']) && is_string($server['HTTP_X_FORWARDED_FOR'])) {
            $xff = trim($server['HTTP_X_FORWARDED_FOR']);
        }
        if ($xff === '' && isset($server['HTTP_X_REAL_IP']) && is_string($server['HTTP_X_REAL_IP'])) {
            $xff = trim($server['HTTP_X_REAL_IP']);
        }
        if ($xff === '') {
            return $remote;
        }

        foreach (explode(',', $xff) as $part) {
            $cand = trim($part);
            if ($cand === '') {
                continue;
            }
            if (filter_var($cand, FILTER_VALIDATE_IP) !== false) {
                return $cand;
            }
        }

        return $remote;
    }

    /**
     * @return non-empty-string
     */
    public static function fromRequest(ServerRequestInterface $request): string
    {
        /** @var array<string, mixed> $server */
        $server = $request->getServerParams();

        return self::fromServerParams($server);
    }

    /**
     * @return list<string>
     */
    private static function trustedEntries(): array
    {
        $raw = $_ENV['CMS_TRUSTED_PROXY_IPS'] ?? getenv('CMS_TRUSTED_PROXY_IPS');
        $raw = is_string($raw) ? trim($raw) : '';
        if ($raw === '') {
            return [];
        }
        $out = [];
        foreach (explode(',', $raw) as $p) {
            $p = trim($p);
            if ($p !== '') {
                $out[] = $p;
            }
        }

        return $out;
    }

    /**
     * @param list<string> $trusted
     */
    private static function addressMatchesTrusted(string $address, array $trusted): bool
    {
        foreach ($trusted as $entry) {
            if ($entry === $address) {
                return true;
            }
            if (str_contains($entry, '/') && self::ipv4InCidr($address, $entry)) {
                return true;
            }
        }

        return false;
    }

    private static function ipv4InCidr(string $ip, string $cidr): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }
        $parts = explode('/', $cidr, 2);
        if (count($parts) !== 2) {
            return false;
        }
        $net = $parts[0];
        $bits = (int) $parts[1];
        if (filter_var($net, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false || $bits < 0 || $bits > 32) {
            return false;
        }
        $ipLong = ip2long($ip);
        $netLong = ip2long($net);
        if ($ipLong === false || $netLong === false) {
            return false;
        }
        $mask = $bits === 0 ? 0 : (~0 << (32 - $bits)) & 0xFFFFFFFF;

        return ($ipLong & $mask) === ($netLong & $mask);
    }
}
