<?php

declare(strict_types=1);

namespace StripeStorePlugin;

use Stripe\Checkout\Session;
use Stripe\Stripe;

final class CheckoutService
{
    public function __construct(
        private readonly string $secretKey,
        private readonly string $defaultCurrency,
    ) {
    }

    /**
     * @param array{
     *   type_slug: string,
     *   entry_slug: string,
     *   entry_id: int,
     *   title: string,
     *   stripe_price_id: ?string,
     *   amount_cents: ?int,
     *   currency: ?string
     * } $product
     */
    public function createSession(
        array $product,
        string $successUrl,
        string $cancelUrl,
    ): Session {
        if ($this->secretKey === '') {
            throw new \RuntimeException('Stripe secret key is not configured.');
        }

        Stripe::setApiKey($this->secretKey);

        $currency = $product['currency'] ?? '';
        if ($currency === '') {
            $currency = $this->defaultCurrency;
        }

        $metadata = [
            'cms_type_slug' => $product['type_slug'],
            'cms_entry_slug' => $product['entry_slug'],
            'cms_entry_id' => (string) $product['entry_id'],
        ];

        if ($product['stripe_price_id'] !== null) {
            return Session::create([
                'mode' => 'payment',
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'metadata' => $metadata,
                'line_items' => [
                    [
                        'price' => $product['stripe_price_id'],
                        'quantity' => 1,
                    ],
                ],
            ]);
        }

        $cents = $product['amount_cents'];
        if ($cents === null || $cents < 1) {
            throw new \RuntimeException('Invalid amount for checkout.');
        }

        return Session::create([
            'mode' => 'payment',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'metadata' => $metadata,
            'line_items' => [
                [
                    'quantity' => 1,
                    'price_data' => [
                        'currency' => $currency,
                        'unit_amount' => $cents,
                        'product_data' => [
                            'name' => $product['title'],
                        ],
                    ],
                ],
            ],
        ]);
    }
}
