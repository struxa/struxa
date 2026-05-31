<?php

declare(strict_types=1);

namespace App\Commerce;

use App\Settings;
use PDO;

/**
 * Commerce settings stored in cms_settings + cms_system_api_keys (provider stripe).
 */
final class CommerceSettings
{
    public const SETTING_ENABLED = 'commerce_enabled';
    public const SETTING_PRODUCT_TYPE_SLUG = 'commerce_product_type_slug';
    public const SETTING_CURRENCY = 'commerce_currency';

    public const STRIPE_PROVIDER = 'stripe';
    public const STRIPE_SECRET = 'secret_key';
    public const STRIPE_PUBLISHABLE = 'publishable_key';
    public const STRIPE_WEBHOOK = 'webhook_secret';

    /** Field keys on the product content type. */
    public const FIELD_PRICE_CENTS = 'price_cents';
    public const FIELD_PURCHASABLE = 'purchasable';
    public const FIELD_STRIPE_PRICE_ID = 'stripe_price_id';
    public const FIELD_SKU = 'sku';
    public const FIELD_STOCK_QTY = 'stock_qty';
    public const FIELD_DIGITAL_FILE = 'digital_file';
    public const FIELD_DIGITAL_URL = 'digital_url';
    public const FIELD_DIGITAL_ENTRY_SLUG = 'digital_entry_slug';
    public const FIELD_DIGITAL_LABEL = 'digital_label';

    public const SETTING_NOTIFY_EMAIL = 'commerce_notify_email';
    public const SETTING_SEND_ORDER_EMAILS = 'commerce_send_order_emails';
    public const SETTING_TRACK_INVENTORY = 'commerce_track_inventory';

    public const SETTING_TAX_ENABLED = 'commerce_tax_enabled';
    public const SETTING_TAX_RATE_BPS = 'commerce_tax_rate_bps';
    public const SETTING_TAX_MODE = 'commerce_tax_mode';
    public const SETTING_USE_SHIPPING_ZONES = 'commerce_use_shipping_zones';
    public const SETTING_LOW_STOCK_THRESHOLD = 'commerce_low_stock_threshold';
    public const SETTING_SHIPPING_ENABLED = 'commerce_shipping_enabled';
    public const SETTING_SHIPPING_FLAT_CENTS = 'commerce_shipping_flat_cents';
    public const SETTING_FREE_SHIPPING_MIN_CENTS = 'commerce_free_shipping_min_cents';
    public const SETTING_SHIPPING_LABEL = 'commerce_shipping_label';
    public const SETTING_SHIPPING_COUNTRIES = 'commerce_shipping_countries';
    public const SETTING_SHOP_TITLE = 'commerce_shop_title';
    public const SETTING_SHOP_DESCRIPTION = 'commerce_shop_description';

    public const TAX_MODE_FLAT = 'flat';
    public const TAX_MODE_COUNTRY = 'country';
    public const TAX_MODE_STRIPE = 'stripe';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function isEnabled(): bool
    {
        return Settings::get(self::SETTING_ENABLED) === '1';
    }

    public function productTypeSlug(): string
    {
        $slug = trim(Settings::get(self::SETTING_PRODUCT_TYPE_SLUG) ?: 'product');

        return $slug !== '' ? $slug : 'product';
    }

    public function defaultCurrency(): string
    {
        $c = strtolower(trim(Settings::get(self::SETTING_CURRENCY) ?: 'gbp'));

        return preg_match('/^[a-z]{3}$/', $c) === 1 ? $c : 'gbp';
    }

    public function stripeSecretKey(): string
    {
        return $this->stripeKey(self::STRIPE_SECRET);
    }

    public function stripePublishableKey(): string
    {
        return $this->stripeKey(self::STRIPE_PUBLISHABLE);
    }

    public function stripeWebhookSecret(): string
    {
        return $this->stripeKey(self::STRIPE_WEBHOOK);
    }

    public function stripeConfigured(): bool
    {
        return $this->stripeSecretKey() !== '';
    }

    public function trackInventory(): bool
    {
        return Settings::get(self::SETTING_TRACK_INVENTORY) !== '0';
    }

    public function sendOrderEmails(): bool
    {
        return Settings::get(self::SETTING_SEND_ORDER_EMAILS) !== '0';
    }

    public function notifyEmail(): string
    {
        return trim(Settings::get(self::SETTING_NOTIFY_EMAIL) ?: '');
    }

    public function taxEnabled(): bool
    {
        return Settings::get(self::SETTING_TAX_ENABLED) === '1';
    }

    public function taxRateBps(): int
    {
        $bps = (int) (Settings::get(self::SETTING_TAX_RATE_BPS) ?: '0');

        return max(0, min(10000, $bps));
    }

    public function taxMode(): string
    {
        $mode = strtolower(trim(Settings::get(self::SETTING_TAX_MODE) ?: self::TAX_MODE_FLAT));

        return in_array($mode, [self::TAX_MODE_FLAT, self::TAX_MODE_COUNTRY, self::TAX_MODE_STRIPE], true)
            ? $mode
            : self::TAX_MODE_FLAT;
    }

    public function useShippingZones(): bool
    {
        return Settings::get(self::SETTING_USE_SHIPPING_ZONES) === '1';
    }

    public function lowStockThreshold(): int
    {
        return max(0, (int) (Settings::get(self::SETTING_LOW_STOCK_THRESHOLD) ?: '5'));
    }

    /** Cart/checkout should collect ship-to country when zones or per-country tax apply. */
    public function needsCheckoutCountry(): bool
    {
        if ($this->useShippingZones() && $this->shippingEnabled()) {
            return true;
        }

        return $this->taxEnabled() && $this->taxMode() === self::TAX_MODE_COUNTRY;
    }

    public function shippingEnabled(): bool
    {
        return Settings::get(self::SETTING_SHIPPING_ENABLED) === '1';
    }

    public function shippingFlatCents(): int
    {
        return max(0, (int) (Settings::get(self::SETTING_SHIPPING_FLAT_CENTS) ?: '0'));
    }

    public function freeShippingMinCents(): int
    {
        return max(0, (int) (Settings::get(self::SETTING_FREE_SHIPPING_MIN_CENTS) ?: '0'));
    }

    public function shippingLabel(): string
    {
        $label = trim(Settings::get(self::SETTING_SHIPPING_LABEL) ?: 'Standard shipping');

        return $label !== '' ? $label : 'Standard shipping';
    }

    /**
     * @return list<string> ISO 3166-1 alpha-2 country codes for Stripe address collection.
     */
    public function shippingCountries(): array
    {
        $raw = trim(Settings::get(self::SETTING_SHIPPING_COUNTRIES) ?: 'GB');
        $parts = preg_split('/[\s,]+/', $raw) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $code = strtoupper(trim((string) $part));
            if (preg_match('/^[A-Z]{2}$/', $code) === 1) {
                $out[] = $code;
            }
        }

        return $out !== [] ? $out : ['GB'];
    }

    public function shopTitle(): string
    {
        $title = trim(Settings::get(self::SETTING_SHOP_TITLE) ?: 'Shop');

        return $title !== '' ? $title : 'Shop';
    }

    public function shopDescription(): string
    {
        return trim(Settings::get(self::SETTING_SHOP_DESCRIPTION) ?: '');
    }

    private function stripeKey(string $name): string
    {
        $st = $this->pdo->prepare(
            'SELECT key_value FROM cms_system_api_keys WHERE provider = ? AND key_name = ? LIMIT 1'
        );
        $st->execute([self::STRIPE_PROVIDER, $name]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? trim((string) ($row['key_value'] ?? '')) : '';
    }
}
