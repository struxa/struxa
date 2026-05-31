<?php

declare(strict_types=1);

namespace App\Commerce\Order;

final class CommerceOrder
{
    /**
     * @param list<CommerceOrderItem> $items
     */
    public function __construct(
        public readonly int $id,
        public readonly string $orderNumber,
        public readonly string $status,
        public readonly string $currency,
        public readonly int $subtotalCents,
        public readonly int $discountCents,
        public readonly int $taxCents,
        public readonly int $shippingCents,
        public readonly int $totalCents,
        public readonly ?string $couponCode,
        public readonly ?string $shippingLabel,
        public readonly ?array $shippingAddress,
        public readonly ?string $customerEmail,
        public readonly ?int $customerUserId,
        public readonly ?string $stripeCheckoutSessionId,
        public readonly ?string $stripePaymentIntentId,
        public readonly ?string $stripeRefundId,
        public readonly string $createdAt,
        public readonly string $updatedAt,
        public readonly ?string $paidAt,
        public readonly ?string $confirmationEmailSentAt,
        public readonly array $items = [],
    ) {
    }

    /**
     * @param array<string, mixed> $row
     * @param list<CommerceOrderItem> $items
     */
    public static function fromRow(array $row, array $items = []): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['order_number'],
            (string) $row['status'],
            (string) $row['currency'],
            (int) $row['subtotal_cents'],
            (int) ($row['discount_cents'] ?? 0),
            (int) ($row['tax_cents'] ?? 0),
            (int) ($row['shipping_cents'] ?? 0),
            (int) $row['total_cents'],
            isset($row['coupon_code']) && $row['coupon_code'] !== '' ? (string) $row['coupon_code'] : null,
            isset($row['shipping_label']) && $row['shipping_label'] !== '' ? (string) $row['shipping_label'] : null,
            self::parseShippingAddress($row['shipping_address_json'] ?? null),
            isset($row['customer_email']) && $row['customer_email'] !== '' ? (string) $row['customer_email'] : null,
            isset($row['customer_user_id']) && $row['customer_user_id'] !== null && (int) $row['customer_user_id'] > 0
                ? (int) $row['customer_user_id'] : null,
            isset($row['stripe_checkout_session_id']) && $row['stripe_checkout_session_id'] !== '' ? (string) $row['stripe_checkout_session_id'] : null,
            isset($row['stripe_payment_intent_id']) && $row['stripe_payment_intent_id'] !== '' ? (string) $row['stripe_payment_intent_id'] : null,
            isset($row['stripe_refund_id']) && $row['stripe_refund_id'] !== '' ? (string) $row['stripe_refund_id'] : null,
            (string) ($row['created_at'] ?? ''),
            (string) ($row['updated_at'] ?? ''),
            isset($row['paid_at']) && $row['paid_at'] !== null && $row['paid_at'] !== '' ? (string) $row['paid_at'] : null,
            isset($row['confirmation_email_sent_at']) && $row['confirmation_email_sent_at'] !== null && $row['confirmation_email_sent_at'] !== ''
                ? (string) $row['confirmation_email_sent_at'] : null,
            $items,
        );
    }

    /**
     * @return list<string>
     */
    public function shippingAddressLines(): array
    {
        return ShippingAddressFormatter::lines($this->shippingAddress);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function parseShippingAddress(mixed $json): ?array
    {
        if ($json === null || $json === '') {
            return null;
        }
        try {
            $decoded = json_decode((string) $json, true, 512, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : null;
        } catch (\JsonException) {
            return null;
        }
    }
}
