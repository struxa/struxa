<?php

declare(strict_types=1);

namespace App\Event;

final class ContentEntryDeletedEvent
{
    public function __construct(
        public readonly int $entryId,
        public readonly int $contentTypeId,
    ) {
    }
}
