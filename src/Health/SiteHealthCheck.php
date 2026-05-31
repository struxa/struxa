<?php

declare(strict_types=1);

namespace App\Health;

final class SiteHealthCheck
{
    /**
     * @param array<string, scalar|null> $actionRouteParams
     */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly string $status,
        public readonly string $message,
        public readonly string $group,
        public readonly ?string $detail = null,
        public readonly ?string $actionRoute = null,
        public readonly ?string $actionLabel = null,
        public readonly array $actionRouteParams = [],
    ) {
    }
}
