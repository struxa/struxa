<?php

declare(strict_types=1);

namespace App\Health;

final class SiteHealthStatus
{
    public const GOOD = 'good';

    public const RECOMMENDED = 'recommended';

    public const CRITICAL = 'critical';

    /** @var list<string> */
    private const SEVERITY_ORDER = [
        self::CRITICAL,
        self::RECOMMENDED,
        self::GOOD,
    ];

    public static function isValid(string $status): bool
    {
        return in_array($status, self::SEVERITY_ORDER, true);
    }

    /**
     * @param list<string> $statuses
     */
    public static function worst(array $statuses): string
    {
        foreach (self::SEVERITY_ORDER as $level) {
            if (in_array($level, $statuses, true)) {
                return $level;
            }
        }

        return self::GOOD;
    }
}
