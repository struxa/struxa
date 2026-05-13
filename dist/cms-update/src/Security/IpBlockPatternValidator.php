<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Normalizes and validates values stored in {@see IpBlockRepository}.
 */
final class IpBlockPatternValidator
{
    public const MAX_LEN = 128;

    /**
     * @return array{ok: true, pattern: string}|array{ok: false, error: string}
     */
    public static function normalize(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return ['ok' => false, 'error' => 'Enter an IP address or IPv4 CIDR.'];
        }
        if (strlen($raw) > self::MAX_LEN) {
            return ['ok' => false, 'error' => 'Pattern is too long.'];
        }
        if (str_contains($raw, '/')) {
            return self::normalizeIpv4Cidr($raw);
        }

        $ip = filter_var($raw, FILTER_VALIDATE_IP);
        if ($ip === false) {
            return ['ok' => false, 'error' => 'Invalid IP address.'];
        }

        return ['ok' => true, 'pattern' => $ip];
    }

    /**
     * @return array{ok: true, pattern: string}|array{ok: false, error: string}
     */
    private static function normalizeIpv4Cidr(string $raw): array
    {
        $parts = explode('/', $raw, 2);
        if (count($parts) !== 2) {
            return ['ok' => false, 'error' => 'Invalid CIDR.'];
        }
        $net = trim($parts[0]);
        $bitsStr = trim($parts[1]);
        if ($bitsStr === '' || !ctype_digit($bitsStr)) {
            return ['ok' => false, 'error' => 'CIDR prefix must be a number.'];
        }
        $bits = (int) $bitsStr;
        if (filter_var($net, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return ['ok' => false, 'error' => 'IPv4 CIDR only (e.g. 203.0.113.0/24). IPv6 ranges are not supported.'];
        }
        if ($bits < 0 || $bits > 32) {
            return ['ok' => false, 'error' => 'IPv4 CIDR prefix must be between 0 and 32.'];
        }
        $ipLong = ip2long($net);
        if ($ipLong === false) {
            return ['ok' => false, 'error' => 'Invalid network address.'];
        }
        $mask = $bits === 0 ? 0 : (~0 << (32 - $bits)) & 0xFFFFFFFF;
        $networkLong = ($ipLong & $mask) & 0xFFFFFFFF;
        $normalizedNet = long2ip($networkLong);

        return ['ok' => true, 'pattern' => $normalizedNet . '/' . $bits];
    }
}
