<?php

declare(strict_types=1);

namespace App\Commerce\Pricing;

use App\Commerce\CommerceSettings;
use App\Commerce\Coupon\CommerceCoupon;

final class OrderTotalsCalculator
{
    public function __construct(private readonly CommerceSettings $commerce)
    {
    }

    public function calculate(int $subtotalCents, ?CommerceCoupon $coupon = null, ?string $couponCode = null): OrderTotals
    {
        $subtotalCents = max(0, $subtotalCents);
        $discountCents = 0;
        $appliedCode = null;

        if ($coupon !== null && $subtotalCents > 0) {
            $discountCents = $coupon->discountForSubtotal($subtotalCents);
            $appliedCode = $coupon->code;
        } elseif ($couponCode !== null && $couponCode !== '') {
            $appliedCode = strtoupper(trim($couponCode));
        }

        $taxable = max(0, $subtotalCents - $discountCents);
        $taxCents = 0;
        if ($this->commerce->taxEnabled() && $taxable > 0) {
            $taxCents = (int) round($taxable * $this->commerce->taxRateBps() / 10000);
        }

        $shippingCents = 0;
        $shippingLabel = null;
        if ($this->commerce->shippingEnabled() && $subtotalCents > 0) {
            $shippingLabel = $this->commerce->shippingLabel();
            $freeMin = $this->commerce->freeShippingMinCents();
            if ($freeMin <= 0 || $taxable < $freeMin) {
                $shippingCents = $this->commerce->shippingFlatCents();
            }
        }

        $totalCents = $taxable + $taxCents + $shippingCents;

        return new OrderTotals(
            $subtotalCents,
            $discountCents,
            $taxCents,
            $shippingCents,
            $totalCents,
            $appliedCode,
            $shippingLabel,
        );
    }
}
