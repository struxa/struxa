<?php

declare(strict_types=1);

namespace App\Jobs;

final class JobStatus
{
    public const PENDING = 'pending';
    public const RUNNING = 'running';
    public const COMPLETED = 'completed';
    public const FAILED = 'failed';
    public const CANCELLED = 'cancelled';

    /** @var list<string> */
    public const ALL = [
        self::PENDING,
        self::RUNNING,
        self::COMPLETED,
        self::FAILED,
        self::CANCELLED,
    ];

    public static function isValid(string $status): bool
    {
        return in_array($status, self::ALL, true);
    }

    public static function isTerminal(string $status): bool
    {
        return in_array($status, [self::COMPLETED, self::FAILED, self::CANCELLED], true);
    }

    public static function isActive(string $status): bool
    {
        return in_array($status, [self::PENDING, self::RUNNING], true);
    }
}
