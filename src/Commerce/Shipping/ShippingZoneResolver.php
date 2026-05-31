<?php

declare(strict_types=1);

namespace App\Commerce\Shipping;

use App\Commerce\CommerceSettings;

final class ShippingZoneResolver
{
    public function __construct(
        private readonly CommerceSettings $commerce,
        private readonly ShippingZoneRepository $zones,
    ) {
    }

    public function quote(int $taxableSubtotalCents, ?string $countryCode): ?ShippingQuote
    {
        if (!$this->commerce->shippingEnabled()) {
            return null;
        }

        if ($this->commerce->useShippingZones()) {
            $zone = $this->matchZone($countryCode);
            if ($zone === null) {
                return null;
            }

            return $this->quoteFromZone($zone, $taxableSubtotalCents);
        }

        $price = $this->commerce->shippingFlatCents();
        $freeMin = $this->commerce->freeShippingMinCents();
        if ($freeMin > 0 && $taxableSubtotalCents >= $freeMin) {
            $price = 0;
        }

        return new ShippingQuote($price, $this->commerce->shippingLabel());
    }

    /**
     * @return list<string>
     */
    public function checkoutCountryAllowlist(): array
    {
        if ($this->commerce->useShippingZones()) {
            $codes = $this->zones->allCountryCodes();
            if ($codes !== []) {
                return $codes;
            }
        }

        return $this->commerce->shippingCountries();
    }

    private function matchZone(?string $countryCode): ?ShippingZone
    {
        $country = strtoupper(trim((string) $countryCode));
        $active = $this->zones->listActiveOrdered();
        if ($active === []) {
            return null;
        }

        $fallback = null;
        foreach ($active as $zone) {
            if ($zone->isFallback()) {
                $fallback = $zone;

                continue;
            }
            if ($country !== '' && in_array($country, $zone->countries, true)) {
                return $zone;
            }
        }

        return $fallback;
    }

    private function quoteFromZone(ShippingZone $zone, int $taxableSubtotalCents): ShippingQuote
    {
        $price = $zone->priceCents;
        if ($zone->freeShippingMinCents > 0 && $taxableSubtotalCents >= $zone->freeShippingMinCents) {
            $price = 0;
        }

        return new ShippingQuote($price, $zone->label, $zone->id);
    }
}
