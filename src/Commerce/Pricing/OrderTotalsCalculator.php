<?php

declare(strict_types=1);

namespace App\Commerce\Pricing;

use App\Commerce\CommerceSettings;
use App\Commerce\Coupon\CommerceCoupon;
use App\Commerce\Shipping\ShippingZoneResolver;
use App\Commerce\Tax\TaxRateResolver;

final class OrderTotalsCalculator
{
    public function __construct(
        private readonly CommerceSettings $commerce,
        private readonly TaxRateResolver $taxRates,
        private readonly ShippingZoneResolver $shippingZones,
    ) {
    }

    public function calculate(
        int $subtotalCents,
        ?CommerceCoupon $coupon = null,
        ?string $couponCode = null,
        ?string $countryCode = null,
    ): OrderTotals {
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
        if ($this->commerce->taxEnabled() && $taxable > 0 && !$this->taxRates->usesStripeTax()) {
            $bps = $this->taxRates->rateBpsForCountry($countryCode);
            if ($bps > 0) {
                $taxCents = (int) round($taxable * $bps / 10000);
            }
        }

        $shippingCents = 0;
        $shippingLabel = null;
        if ($this->commerce->shippingEnabled() && $subtotalCents > 0) {
            $quote = $this->shippingZones->quote($taxable, $countryCode);
            if ($quote !== null) {
                $shippingCents = $quote->priceCents;
                $shippingLabel = $quote->label;
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
