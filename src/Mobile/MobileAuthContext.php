<?php

declare(strict_types=1);

namespace App\Mobile;

final class MobileAuthContext
{
    public const ATTR = 'mobile_auth';

    public function __construct(
        public readonly int $userId,
        public readonly string $email,
    ) {
    }
}
