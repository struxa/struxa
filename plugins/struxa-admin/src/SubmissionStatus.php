<?php

declare(strict_types=1);

namespace StruxaAdmin;

final class SubmissionStatus
{
    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';

    /** @var list<string> */
    public const ALL = [self::PENDING, self::APPROVED, self::REJECTED];

    public static function isValid(string $status): bool
    {
        return in_array($status, self::ALL, true);
    }
}
