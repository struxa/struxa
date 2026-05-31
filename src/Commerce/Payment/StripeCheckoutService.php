<?php

declare(strict_types=1);

namespace App\Commerce\Payment;

use App\Commerce\Cart\CartLine;
use App\Commerce\CommerceSettings;
use App\Commerce\Order\CommerceOrder;
use App\Commerce\Order\CommerceOrderRepository;
use App\Commerce\Pricing\OrderTotals;
use App\Commerce\Product\PurchasableProduct;
use Stripe\Checkout\Session;
use Stripe\Coupon;
use Stripe\Stripe;

final class StripeCheckoutService
{
    public function __construct(
        private readonly CommerceSettings $commerce,
        private readonly CommerceOrderRepository $orders,
    ) {
    }

    /**
     * @return array{ok: true, redirect_url: string, order: CommerceOrder}|array{ok: false, error: string}
     */
    public function startCheckout(PurchasableProduct $product, string $siteUrl, int $quantity = 1, ?OrderTotals $totals = null, ?int $customerUserId = null): array
    {
        $quantity = max(1, min(99, $quantity));
        $lineTotal = $product->stripePriceId !== null ? 0 : $product->priceCents * $quantity;
        $totals ??= new OrderTotals($lineTotal, 0, 0, 0, $lineTotal, null, null);

        return $this->createSession(
            [[
                'content_entry_id' => $product->entryId,
                'content_type_id' => $product->contentTypeId,
                'title' => $product->title,
                'unit_price_cents' => $product->priceCents,
                'quantity' => $quantity,
                'line_total_cents' => $lineTotal,
                'metadata_json' => json_encode([
                    'entry_slug' => $product->entrySlug,
                    'type_slug' => $product->contentTypeSlug,
                    'sku' => $product->sku,
                ], JSON_THROW_ON_ERROR),
                'stripe_line' => $this->stripeLineItem($product, $quantity),
            ]],
            $product->currency,
            $totals,
            rtrim($siteUrl, '/') . '/' . rawurlencode($product->contentTypeSlug) . '/' . rawurlencode($product->entrySlug) . '?checkout=cancelled',
            $siteUrl,
            ['entry_id' => (string) $product->entryId],
            $customerUserId,
        );
    }

    /**
     * @param list<CartLine> $cartLines
     * @return array{ok: true, redirect_url: string, order: CommerceOrder}|array{ok: false, error: string}
     */
    public function startCartCheckout(array $cartLines, string $siteUrl, OrderTotals $totals, ?int $customerUserId = null): array
    {
        if ($cartLines === []) {
            return ['ok' => false, 'error' => 'Your cart is empty.'];
        }

        $currency = $cartLines[0]->product->currency;
        $items = [];
        foreach ($cartLines as $line) {
            $p = $line->product;
            $items[] = [
                'content_entry_id' => $p->entryId,
                'content_type_id' => $p->contentTypeId,
                'title' => $p->title,
                'unit_price_cents' => $p->priceCents,
                'quantity' => $line->quantity,
                'line_total_cents' => $line->lineTotalCents,
                'metadata_json' => json_encode([
                    'entry_slug' => $p->entrySlug,
                    'type_slug' => $p->contentTypeSlug,
                    'sku' => $p->sku,
                ], JSON_THROW_ON_ERROR),
                'stripe_line' => $this->stripeLineItem($p, $line->quantity),
            ];
        }

        return $this->createSession(
            $items,
            $currency,
            $totals,
            rtrim($siteUrl, '/') . '/commerce/cart?checkout=cancelled',
            $siteUrl,
            ['cart' => '1'],
            $customerUserId,
        );
    }

    /**
     * @param list<array{content_entry_id: int, content_type_id: int, title: string, unit_price_cents: int, quantity: int, line_total_cents: int, metadata_json: string, stripe_line: array<string, mixed>}> $items
     * @param array<string, string> $sessionMeta
     * @return array{ok: true, redirect_url: string, order: CommerceOrder}|array{ok: false, error: string}
     */
    private function createSession(array $items, string $currency, OrderTotals $totals, string $cancelUrl, string $siteUrl, array $sessionMeta, ?int $customerUserId = null): array
    {
        $secret = $this->commerce->stripeSecretKey();
        if ($secret === '') {
            return ['ok' => false, 'error' => 'Stripe is not configured.'];
        }

        $orderItems = array_map(static fn (array $i): array => [
            'content_entry_id' => $i['content_entry_id'],
            'content_type_id' => $i['content_type_id'],
            'title' => $i['title'],
            'unit_price_cents' => $i['unit_price_cents'],
            'quantity' => $i['quantity'],
            'line_total_cents' => $i['line_total_cents'],
            'metadata_json' => $i['metadata_json'],
        ], $items);

        $order = $this->orders->createPending([
            'currency' => $currency,
            'subtotal_cents' => $totals->subtotalCents,
            'discount_cents' => $totals->discountCents,
            'tax_cents' => $totals->taxCents,
            'shipping_cents' => $totals->shippingCents,
            'total_cents' => $totals->totalCents,
            'coupon_code' => $totals->couponCode,
            'shipping_label' => $totals->shippingLabel,
            'customer_user_id' => $customerUserId,
        ], $orderItems);

        Stripe::setApiKey($secret);

        $lineItems = array_map(static fn (array $i): array => $i['stripe_line'], $items);
        if ($totals->taxCents > 0) {
            $lineItems[] = $this->adjustmentLine($currency, $totals->taxCents, 'Tax');
        }
        if ($totals->shippingCents > 0) {
            $lineItems[] = $this->adjustmentLine($currency, $totals->shippingCents, $totals->shippingLabel ?? 'Shipping');
        }

        $sessionParams = [
            'mode' => 'payment',
            'line_items' => $lineItems,
            'success_url' => rtrim($siteUrl, '/') . '/commerce/checkout/success?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $cancelUrl,
            'client_reference_id' => (string) $order->id,
            'metadata' => array_merge(['order_number' => $order->orderNumber], $sessionMeta),
        ];

        if ($totals->discountCents > 0) {
            try {
                $coupon = Coupon::create([
                    'amount_off' => $totals->discountCents,
                    'currency' => $currency,
                    'duration' => 'once',
                    'name' => $totals->couponCode ?? 'Discount',
                ]);
                $sessionParams['discounts'] = [['coupon' => $coupon->id]];
            } catch (\Throwable) {
                $this->orders->markFailed($order->id);

                return ['ok' => false, 'error' => 'Could not apply coupon discount in Stripe.'];
            }
        }

        if ($this->commerce->shippingEnabled() || $totals->shippingCents > 0) {
            $sessionParams['shipping_address_collection'] = [
                'allowed_countries' => $this->commerce->shippingCountries(),
            ];
        }

        try {
            $session = Session::create($sessionParams);
        } catch (\Throwable) {
            $this->orders->markFailed($order->id);

            return ['ok' => false, 'error' => 'Could not start Stripe Checkout.'];
        }

        $this->orders->attachStripeSession($order->id, $session->id);
        $updated = $this->orders->findById($order->id);
        if ($updated === null) {
            return ['ok' => false, 'error' => 'Order could not be loaded.'];
        }

        return [
            'ok' => true,
            'redirect_url' => (string) $session->url,
            'order' => $updated,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function stripeLineItem(PurchasableProduct $product, int $quantity): array
    {
        if ($product->stripePriceId !== null) {
            return ['price' => $product->stripePriceId, 'quantity' => $quantity];
        }

        return [
            'price_data' => [
                'currency' => $product->currency,
                'unit_amount' => $product->priceCents,
                'product_data' => array_filter([
                    'name' => $product->title,
                    'metadata' => array_filter([
                        'entry_id' => (string) $product->entryId,
                        'sku' => $product->sku,
                    ]),
                ]),
            ],
            'quantity' => $quantity,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function adjustmentLine(string $currency, int $amountCents, string $name): array
    {
        return [
            'price_data' => [
                'currency' => $currency,
                'unit_amount' => $amountCents,
                'product_data' => ['name' => $name],
            ],
            'quantity' => 1,
        ];
    }
}
