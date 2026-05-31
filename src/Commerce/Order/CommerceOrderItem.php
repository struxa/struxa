<?php

declare(strict_types=1);

namespace App\Commerce\Order;

final class CommerceOrderItem
{
    public function __construct(
        public readonly int $id,
        public readonly int $orderId,
        public readonly int $contentEntryId,
        public readonly int $contentTypeId,
        public readonly string $title,
        public readonly int $unitPriceCents,
        public readonly int $quantity,
        public readonly int $lineTotalCents,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['order_id'],
            (int) $row['content_entry_id'],
            (int) $row['content_type_id'],
            (string) $row['title'],
            (int) $row['unit_price_cents'],
            (int) $row['quantity'],
            (int) $row['line_total_cents'],
        );
    }
}
