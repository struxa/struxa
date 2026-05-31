<?php

declare(strict_types=1);

namespace App\Commerce\Cart;

use App\Commerce\CommerceSettings;
use App\Commerce\Coupon\CouponService;
use App\Commerce\Pricing\OrderTotals;
use App\Commerce\Pricing\OrderTotalsCalculator;
use App\Commerce\Product\ProductResolver;
use App\Content\ContentEntryRepository;
use App\Content\ContentEntryValueRepository;
use App\Content\ContentTypeRepository;

/**
 * Hydrates session cart lines into purchasable products with stock checks.
 */
final class CartResolver
{
    public function __construct(
        private readonly CartService $cart,
        private readonly CommerceSettings $commerce,
        private readonly ContentTypeRepository $types,
        private readonly ContentEntryRepository $entries,
        private readonly ContentEntryValueRepository $values,
        private readonly ProductResolver $products,
        private readonly OrderTotalsCalculator $totalsCalculator,
        private readonly CouponService $coupons,
    ) {
    }

    /**
     * @return array{
     *   ok: true,
     *   lines: list<CartLine>,
     *   subtotal_cents: int,
     *   currency: string,
     *   totals: OrderTotals,
     *   coupon_code: ?string,
     *   coupon_error: ?string,
     * }|array{ok: false, error: string}
     */
    public function resolve(): array
    {
        if (!$this->commerce->isEnabled()) {
            return ['ok' => false, 'error' => 'Commerce is disabled.'];
        }

        $typeSlug = $this->commerce->productTypeSlug();
        $type = $this->types->findBySlug($typeSlug);
        if ($type === null) {
            return ['ok' => false, 'error' => 'Product content type is not configured.'];
        }

        $lines = [];
        $subtotal = 0;
        $currency = $this->commerce->defaultCurrency();
        $hasStripePrice = false;

        foreach ($this->cart->lines() as $entryId => $qty) {
            $entry = $this->entries->findById($entryId);
            if ($entry === null || $entry->contentTypeId !== $type->id) {
                $this->cart->remove($entryId);

                continue;
            }
            $valueMap = $this->values->valuesByFieldIdForEntry($entryId);
            $product = $this->products->resolvePublished($type, $entry, $valueMap);
            if ($product === null) {
                $this->cart->remove($entryId);

                continue;
            }
            if (!$this->products->hasStock($product, $qty)) {
                return ['ok' => false, 'error' => sprintf('"%s" is not available in the requested quantity.', $product->title)];
            }
            if ($product->stripePriceId !== null) {
                $hasStripePrice = true;
                $lineTotal = 0;
            } else {
                $lineTotal = $product->priceCents * $qty;
            }
            $subtotal += $lineTotal;
            $lines[] = new CartLine($product, $qty, $lineTotal);
        }

        $coupon = null;
        $couponError = null;
        $couponCode = $this->cart->couponCode();
        if ($couponCode !== null) {
            if ($hasStripePrice) {
                $this->cart->clearCoupon();
                $couponError = 'Coupons cannot be used with Stripe Price ID products.';
                $couponCode = null;
            } else {
                $validation = $this->coupons->validateForSubtotal($couponCode, $subtotal);
                if (!$validation['ok']) {
                    $this->cart->clearCoupon();
                    $couponError = $validation['error'];
                    $couponCode = null;
                } else {
                    $coupon = $validation['coupon'];
                }
            }
        }

        $totals = $this->totalsCalculator->calculate($subtotal, $coupon, $couponCode);

        return [
            'ok' => true,
            'lines' => $lines,
            'subtotal_cents' => $subtotal,
            'currency' => $currency,
            'totals' => $totals,
            'coupon_code' => $couponCode,
            'coupon_error' => $couponError,
        ];
    }

    /**
     * @param list<CartLine> $lines
     */
    public function validateForCheckout(array $lines): ?string
    {
        if ($lines === []) {
            return 'Your cart is empty.';
        }
        foreach ($lines as $line) {
            if (!$this->products->hasStock($line->product, $line->quantity)) {
                return sprintf('"%s" is out of stock.', $line->product->title);
            }
            if ($line->product->stripePriceId !== null && $this->cart->couponCode() !== null) {
                return 'Remove the coupon before checking out Stripe Price ID products.';
            }
        }

        return null;
    }
}
