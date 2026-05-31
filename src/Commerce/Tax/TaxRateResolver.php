<?php

declare(strict_types=1);

namespace App\Commerce\Tax;

use App\Commerce\CommerceSettings;

final class TaxRateResolver
{
    public function __construct(
        private readonly CommerceSettings $commerce,
        private readonly TaxRateRepository $rates,
    ) {
    }

    public function rateBpsForCountry(?string $countryCode): int
    {
        if (!$this->commerce->taxEnabled()) {
            return 0;
        }

        $mode = $this->commerce->taxMode();
        if ($mode === CommerceSettings::TAX_MODE_STRIPE) {
            return 0;
        }
        if ($mode === CommerceSettings::TAX_MODE_COUNTRY) {
            $country = strtoupper(trim((string) $countryCode));
            if ($country !== '') {
                $rate = $this->rates->findByCountry($country);
                if ($rate !== null) {
                    return $rate->rateBps;
                }
            }

            return 0;
        }

        return $this->commerce->taxRateBps();
    }

    public function usesStripeTax(): bool
    {
        return $this->commerce->taxEnabled() && $this->commerce->taxMode() === CommerceSettings::TAX_MODE_STRIPE;
    }
}
