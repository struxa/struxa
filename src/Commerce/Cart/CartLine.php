<?php

declare(strict_types=1);

namespace App\Commerce\Cart;

use App\Commerce\Product\PurchasableProduct;

/** Resolved cart row for templates and checkout. */
final class CartLine
{
    public function __construct(
        public readonly PurchasableProduct $product,
        public readonly int $quantity,
        public readonly int $lineTotalCents,
    ) {
    }
}
