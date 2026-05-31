<?php

declare(strict_types=1);

namespace App\Commerce\Shipping;

final class ShippingQuote
{
    public function __construct(
        public readonly int $priceCents,
        public readonly string $label,
        public readonly ?int $zoneId = null,
    ) {
    }
}
