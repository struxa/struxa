<?php

declare(strict_types=1);

namespace App\Content;

/**
 * Outcome of a bulk entry operation (publish, trash, taxonomy).
 */
final class ContentEntryBulkResult
{
    /**
     * @param list<string> $skipReasons
     * @param list<int> $appliedIds
     */
    public function __construct(
        public readonly int $applied = 0,
        public readonly int $skipped = 0,
        public readonly array $skipReasons = [],
        public readonly array $appliedIds = [],
    ) {
    }

    public function total(): int
    {
        return $this->applied + $this->skipped;
    }
}
