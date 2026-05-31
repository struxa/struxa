<?php

declare(strict_types=1);

namespace App\Jobs;

use PDO;

final class JobHandlerContext
{
    public function __construct(
        public readonly PDO $pdo,
        public readonly string $projectRoot,
        public readonly JobQueue $queue,
    ) {
    }
}
