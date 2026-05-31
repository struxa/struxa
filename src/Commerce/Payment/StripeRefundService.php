<?php

declare(strict_types=1);

namespace App\Commerce\Payment;

use App\Commerce\CommerceSettings;
use App\Commerce\Order\CommerceOrderRepository;
use Stripe\Refund;
use Stripe\Stripe;

final class StripeRefundService
{
    public function __construct(
        private readonly CommerceSettings $commerce,
        private readonly CommerceOrderRepository $orders,
    ) {
    }

    /**
     * @return array{ok: true, refund_id: string}|array{ok: false, error: string}
     */
    public function refundOrder(int $orderId): array
    {
        $order = $this->orders->findById($orderId);
        if ($order === null) {
            return ['ok' => false, 'error' => 'Order not found.'];
        }
        if ($order->status !== 'paid') {
            return ['ok' => false, 'error' => 'Only paid orders can be refunded.'];
        }
        if ($order->stripePaymentIntentId === null || $order->stripePaymentIntentId === '') {
            return ['ok' => false, 'error' => 'No Stripe payment intent on this order.'];
        }

        $secret = $this->commerce->stripeSecretKey();
        if ($secret === '') {
            return ['ok' => false, 'error' => 'Stripe is not configured.'];
        }

        Stripe::setApiKey($secret);

        try {
            $refund = Refund::create(['payment_intent' => $order->stripePaymentIntentId]);
        } catch (\Throwable) {
            return ['ok' => false, 'error' => 'Stripe refund failed.'];
        }

        $refundId = (string) ($refund->id ?? '');
        $this->orders->markRefunded($orderId, $refundId);

        return ['ok' => true, 'refund_id' => $refundId];
    }
}
