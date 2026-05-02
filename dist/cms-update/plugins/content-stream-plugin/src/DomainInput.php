<?php

declare(strict_types=1);

namespace ContentStreamPlugin;

/**
 * Normalise and validate a hostname the visitor typed (no scheme/path).
 */
final class DomainInput
{
    public static function parse(string $raw): ?string
    {
        $d = strtolower(trim($raw));
        $d = preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', $d) ?? $d;
        $d = explode('/', $d, 2)[0];
        $d = explode(':', $d, 2)[0];
        $d = trim($d, '.');
        if ($d === '' || strlen($d) > 253) {
            return null;
        }
        if ($d === 'localhost') {
            return 'localhost';
        }
        if (filter_var($d, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            return null;
        }

        return $d;
    }
}
