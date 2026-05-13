<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Tests the resolved client IP against stored block patterns (exact IPv4/IPv6, IPv4 CIDR).
 */
final class IpBlockMatcher
{
    /**
     * @param list<string> $patterns
     */
    public static function isBlocked(string $clientIp, array $patterns): bool
    {
        foreach ($patterns as $p) {
            $p = trim($p);
            if ($p === '') {
                continue;
            }
            if (str_contains($p, '/')) {
                if (self::ipv4InCidr($clientIp, $p)) {
                    return true;
                }

                continue;
            }
            if (self::ipEquals($clientIp, $p)) {
                return true;
            }
        }

        return false;
    }

    private static function ipEquals(string $a, string $b): bool
    {
        if (filter_var($a, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            && filter_var($b, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $a === $b;
        }
        $ba = @inet_pton($a);
        $bb = @inet_pton($b);

        return $ba !== false && $bb !== false && $ba === $bb;
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
