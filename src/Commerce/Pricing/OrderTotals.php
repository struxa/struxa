<?php

declare(strict_types=1);

namespace App\Commerce\Pricing;

/** Computed cart/checkout totals in minor currency units. */
final class OrderTotals
{
    public function __construct(
        public readonly int $subtotalCents,
        public readonly int $discountCents,
        public readonly int $taxCents,
        public readonly int $shippingCents,
        public readonly int $totalCents,
        public readonly ?string $couponCode,
        public readonly ?string $shippingLabel,
    ) {
    }

    public function taxableCents(): int
    {
        return max(0, $this->subtotalCents - $this->discountCents);
    }
}
