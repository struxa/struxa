<?php

declare(strict_types=1);

namespace App\Access;

use PDO;

final class ActivityLogger
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array<string, mixed> $details
     */
    public function log(?int $userId, string $eventType, ?string $subjectType = null, ?int $subjectId = null, array $details = []): void
    {
        try {
            (new ActivityLogRepository($this->pdo))->insert($userId, $eventType, $subjectType, $subjectId, $details);
        } catch (\Throwable) {
            // Never break primary flow on audit failure
        }
    }
}
