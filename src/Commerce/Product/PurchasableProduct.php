<?php

declare(strict_types=1);

namespace App\Commerce\Product;

/**
 * A purchasable content entry resolved from custom fields.
 */
final class PurchasableProduct
{
    public function __construct(
        public readonly int $entryId,
        public readonly int $contentTypeId,
        public readonly string $contentTypeSlug,
        public readonly string $entrySlug,
        public readonly string $title,
        public readonly int $priceCents,
        public readonly string $currency,
        public readonly ?string $stripePriceId,
        public readonly ?string $sku,
        public readonly ?int $stockQty,
    ) {
    }

    /** Unlimited stock when null. */
    public function isInStock(int $requestedQty = 1): bool
    {
        if ($this->stockQty === null) {
            return true;
        }

        return $this->stockQty >= max(1, $requestedQty);
    }

    public function formattedPrice(): string
    {
        $amount = $this->priceCents / 100;
        $symbol = match ($this->currency) {
            'gbp' => '£',
            'eur' => '€',
            'usd' => '$',
            default => strtoupper($this->currency) . ' ',
        };

        return $symbol . number_format($amount, 2);
    }
}
