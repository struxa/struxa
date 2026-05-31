<?php

declare(strict_types=1);

namespace App\Commerce\Cart;

/**
 * Session-backed shopping cart (entry id => quantity).
 */
final class CartService
{
    private const SESSION_KEY = 'struxa_commerce_cart';
    private const COUPON_KEY = 'struxa_commerce_coupon';
    private const COUNTRY_KEY = 'struxa_commerce_ship_country';

    /**
     * @return array<int, int> entry_id => quantity
     */
    public function lines(): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return [];
        }
        /** @var mixed $raw */
        $raw = $_SESSION[self::SESSION_KEY] ?? [];
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $entryId => $qty) {
            $id = (int) $entryId;
            $q = (int) $qty;
            if ($id > 0 && $q > 0) {
                $out[$id] = min(99, $q);
            }
        }

        return $out;
    }

    public function count(): int
    {
        return array_sum($this->lines());
    }

    public function isEmpty(): bool
    {
        return $this->lines() === [];
    }

    public function add(int $entryId, int $quantity = 1): void
    {
        $this->ensureSession();
        $entryId = max(1, $entryId);
        $quantity = max(1, min(99, $quantity));
        $lines = $this->lines();
        $lines[$entryId] = min(99, ($lines[$entryId] ?? 0) + $quantity);
        $_SESSION[self::SESSION_KEY] = $lines;
    }

    public function setQuantity(int $entryId, int $quantity): void
    {
        $this->ensureSession();
        $entryId = max(1, $entryId);
        $lines = $this->lines();
        if ($quantity < 1) {
            unset($lines[$entryId]);
        } else {
            $lines[$entryId] = min(99, $quantity);
        }
        $_SESSION[self::SESSION_KEY] = $lines;
    }

    public function remove(int $entryId): void
    {
        $this->setQuantity($entryId, 0);
    }

    public function clear(): void
    {
        $this->ensureSession();
        unset($_SESSION[self::SESSION_KEY]);
        unset($_SESSION[self::COUPON_KEY]);
        unset($_SESSION[self::COUNTRY_KEY]);
    }

    public function shipCountry(): ?string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return null;
        }
        /** @var mixed $code */
        $code = $_SESSION[self::COUNTRY_KEY] ?? null;
        if (!is_string($code)) {
            return null;
        }
        $code = strtoupper(trim($code));

        return preg_match('/^[A-Z]{2}$/', $code) === 1 ? $code : null;
    }

    public function setShipCountry(?string $countryCode): void
    {
        $this->ensureSession();
        if ($countryCode === null || trim($countryCode) === '') {
            unset($_SESSION[self::COUNTRY_KEY]);

            return;
        }
        $code = strtoupper(trim($countryCode));
        if (preg_match('/^[A-Z]{2}$/', $code) !== 1) {
            unset($_SESSION[self::COUNTRY_KEY]);

            return;
        }
        $_SESSION[self::COUNTRY_KEY] = $code;
    }

    public function couponCode(): ?string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return null;
        }
        /** @var mixed $code */
        $code = $_SESSION[self::COUPON_KEY] ?? null;

        return is_string($code) && trim($code) !== '' ? strtoupper(trim($code)) : null;
    }

    public function setCouponCode(?string $code): void
    {
        $this->ensureSession();
        if ($code === null || trim($code) === '') {
            unset($_SESSION[self::COUPON_KEY]);

            return;
        }
        $_SESSION[self::COUPON_KEY] = strtoupper(trim($code));
    }

    public function clearCoupon(): void
    {
        $this->setCouponCode(null);
    }

    private function ensureSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }
}
