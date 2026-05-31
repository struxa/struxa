<?php

declare(strict_types=1);

namespace App\Commerce\Payment;

use App\Commerce\CommerceSettings;
use App\Commerce\Customer\CommerceCustomerLinker;
use App\Commerce\Order\CommerceOrderRepository;
use App\Commerce\Order\OrderFulfillmentService;
use App\Commerce\Order\ShippingAddressFormatter;
use Stripe\Webhook;

final class StripeWebhookHandler
{
    public function __construct(
        private readonly CommerceSettings $commerce,
        private readonly CommerceOrderRepository $orders,
        private readonly OrderFulfillmentService $fulfillment,
        private readonly CommerceCustomerLinker $customerLinker,
    ) {
    }

    /**
     * @return array{ok: true}|array{ok: false, error: string, status: int}
     */
    public function handle(string $payload, ?string $signatureHeader): array
    {
        $secret = $this->commerce->stripeWebhookSecret();
        if ($secret === '') {
            return ['ok' => false, 'error' => 'Webhook secret not configured.', 'status' => 503];
        }
        if ($signatureHeader === null || $signatureHeader === '') {
            return ['ok' => false, 'error' => 'Missing Stripe signature.', 'status' => 400];
        }

        try {
            $event = Webhook::constructEvent($payload, $signatureHeader, $secret);
        } catch (\UnexpectedValueException) {
            return ['ok' => false, 'error' => 'Invalid payload.', 'status' => 400];
        } catch (\Stripe\Exception\SignatureVerificationException) {
            return ['ok' => false, 'error' => 'Invalid signature.', 'status' => 400];
        }

        $type = (string) $event->type;
        $object = $event->data->object ?? null;

        if ($type === 'checkout.session.completed' && $object !== null) {
            $sessionId = (string) ($object->id ?? '');
            $order = $sessionId !== '' ? $this->orders->findByStripeSessionId($sessionId) : null;
            if ($order !== null) {
                $email = isset($object->customer_details->email) ? (string) $object->customer_details->email : null;
                $pi = isset($object->payment_intent) ? (string) $object->payment_intent : null;
                if (isset($object->shipping_details) && is_object($object->shipping_details)) {
                    $addr = ShippingAddressFormatter::fromStripeObject($object->shipping_details);
                    if ($addr !== null) {
                        $this->orders->saveShippingAddress($order->id, $addr);
                    }
                }
                if ($this->orders->markPaid($order->id, $pi, $email)) {
                    $this->customerLinker->linkOrderAfterPayment($order->id, $email, $order->customerUserId);
                    $this->fulfillment->fulfillIfPaid($order->id);
                } else {
                    $this->customerLinker->linkOrderAfterPayment($order->id, $email, $order->customerUserId);
                    $this->fulfillment->fulfillIfPaid($order->id);
                }
            }
        }

        if ($type === 'checkout.session.expired' && $object !== null) {
            $sessionId = (string) ($object->id ?? '');
            $order = $sessionId !== '' ? $this->orders->findByStripeSessionId($sessionId) : null;
            if ($order !== null && $order->status === 'pending') {
                $this->orders->markCancelled($order->id);
            }
        }

        return ['ok' => true];
    }
}
