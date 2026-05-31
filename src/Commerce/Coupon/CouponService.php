<?php

declare(strict_types=1);

namespace App\Commerce\Coupon;

final class CouponService
{
    public function __construct(private readonly CouponRepository $coupons)
    {
    }

    /**
     * @return array{ok: true, coupon: CommerceCoupon}|array{ok: false, error: string}
     */
    public function validateForSubtotal(string $code, int $subtotalCents): array
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return ['ok' => false, 'error' => 'Enter a coupon code.'];
        }

        $coupon = $this->coupons->findByCode($code);
        if ($coupon === null) {
            return ['ok' => false, 'error' => 'Coupon code is not valid.'];
        }
        if (!$coupon->isUsable()) {
            return ['ok' => false, 'error' => 'This coupon is no longer available.'];
        }
        if ($subtotalCents < $coupon->minSubtotalCents) {
            $min = number_format($coupon->minSubtotalCents / 100, 2);

            return ['ok' => false, 'error' => sprintf('Minimum order subtotal is %s for this coupon.', $min)];
        }
        if ($coupon->discountForSubtotal($subtotalCents) <= 0) {
            return ['ok' => false, 'error' => 'Coupon does not apply to this order.'];
        }

        return ['ok' => true, 'coupon' => $coupon];
    }

    public function recordRedemption(?string $code): void
    {
        if ($code === null || trim($code) === '') {
            return;
        }
        $coupon = $this->coupons->findByCode($code);
        if ($coupon !== null) {
            $this->coupons->incrementUses($coupon->id);
        }
    }
}
