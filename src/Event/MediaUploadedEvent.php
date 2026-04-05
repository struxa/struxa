<?php

declare(strict_types=1);

namespace App\Event;

final class MediaUploadedEvent
{
    public function __construct(
        public readonly int $mediaId,
    ) {
    }
}
