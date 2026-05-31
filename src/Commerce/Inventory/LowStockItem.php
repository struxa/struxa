<?php

declare(strict_types=1);

namespace App\Commerce\Inventory;

final class LowStockItem
{
    public function __construct(
        public readonly int $entryId,
        public readonly string $title,
        public readonly string $slug,
        public readonly int $stockQty,
        public readonly int $contentTypeId,
    ) {
    }
}
