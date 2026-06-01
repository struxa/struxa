<?php

declare(strict_types=1);

namespace App\Mobile;

use App\Commerce\Cart\CartLine;
use App\Commerce\CommerceCountryCodes;
use App\Commerce\CommerceSettings;
use App\Commerce\Coupon\CouponRepository;
use App\Commerce\Coupon\CouponService;
use App\Commerce\Digital\DigitalFulfillmentService;
use App\Commerce\Digital\DigitalGrant;
use App\Commerce\Order\CommerceOrder;
use App\Commerce\Order\CommerceOrderRepository;
use App\Commerce\Payment\StripeCheckoutService;
use App\Commerce\Pricing\OrderTotals;
use App\Commerce\Pricing\OrderTotalsCalculator;
use App\Commerce\Product\ProductResolver;
use App\Commerce\Shipping\ShippingZoneRepository;
use App\Commerce\Tax\TaxRateRepository;
use App\Commerce\Tax\TaxRateResolver;
use App\Commerce\Shipping\ShippingZoneResolver;
use App\Content\ContentEntryRepository;
use App\Content\ContentEntryValueRepository;
use App\Content\ContentFieldRepository;
use App\Content\ContentTypeRepository;
use App\Content\PublicContentIndexPager;
use App\Media\MediaUrlHelper;
use App\Settings\SiteUrlResolver;
use PDO;

/**
 * Commerce catalog, cart quote, Stripe checkout, and order history for the mobile app.
 */
final class MobileCommerceService
{
    public const PER_PAGE_DEFAULT = 20;
    public const PER_PAGE_MAX = 30;
    public const MAX_CART_LINES = 50;

    private readonly CommerceSettings $commerce;
    private readonly ProductResolver $products;
    private readonly ContentTypeRepository $types;
    private readonly ContentEntryRepository $entries;
    private readonly ContentEntryValueRepository $values;
    private readonly OrderTotalsCalculator $totalsCalculator;
    private readonly CouponService $coupons;
    private readonly CommerceOrderRepository $orders;
    private readonly StripeCheckoutService $checkout;
    private readonly DigitalFulfillmentService $digital;

    public function __construct(private readonly PDO $pdo)
    {
        $this->commerce = new CommerceSettings($pdo);
        $fields = new ContentFieldRepository($pdo);
        $this->products = new ProductResolver($pdo, $this->commerce, $fields);
        $this->types = new ContentTypeRepository($pdo);
        $this->entries = new ContentEntryRepository($pdo);
        $this->values = new ContentEntryValueRepository($pdo);
        $taxRates = new TaxRateResolver($this->commerce, new TaxRateRepository($pdo));
        $shippingZones = new ShippingZoneResolver($this->commerce, new ShippingZoneRepository($pdo));
        $this->totalsCalculator = new OrderTotalsCalculator($this->commerce, $taxRates, $shippingZones);
        $this->coupons = new CouponService(new CouponRepository($pdo));
        $this->orders = new CommerceOrderRepository($pdo);
        $this->checkout = new StripeCheckoutService($this->commerce, $this->orders, $taxRates, $shippingZones);
        $this->digital = DigitalFulfillmentService::factory(
            $pdo,
            $this->commerce,
            $this->types,
            $fields,
            $this->values,
            $this->entries,
            $this->orders,
        );
    }

    /**
     * @return array{items: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function listProducts(int $page, int $perPage): array
    {
        $this->assertCommerceEnabled();
        $type = $this->requireProductType();
        $page = max(1, $page);
        $perPage = max(1, min(self::PER_PAGE_MAX, $perPage));

        $total = $this->entries->countPublishedForContentType($type->id);
        $totalPages = max(1, (int) ceil(max(0, $total) / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }

        $rows = $this->entries->publishedForContentTypePaged($type->id, $page, $perPage);
        $siteUrl = SiteUrlResolver::resolve();
        $mediaUrls = new MediaUrlHelper($this->pdo);
        $items = [];
        foreach ($rows as $row) {
            $item = $this->productFromRow($type, $row, $siteUrl, $mediaUrls);
            if ($item !== null) {
                $items[] = $item;
            }
        }

        return [
            'items' => $items,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $totalPages,
                'page_items' => PublicContentIndexPager::pageItems($page, $totalPages),
                'product_type_slug' => $type->slug,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function productDetail(string $entrySlug): array
    {
        $this->assertCommerceEnabled();
        $type = $this->requireProductType();
        $entrySlug = trim($entrySlug);
        if ($entrySlug === '') {
            throw new MobileCommerceException('not_found', 'Product not found.', 404);
        }

        $entry = $this->entries->findPublishedByTypeSlug($type->id, $entrySlug);
        if ($entry === null) {
            throw new MobileCommerceException('not_found', 'Product not found.', 404);
        }

        $valueMap = $this->values->valuesByFieldIdForEntry($entry->id);
        $product = $this->products->resolvePublished($type, $entry, $valueMap, requireInStock: false);
        if ($product === null) {
            throw new MobileCommerceException('not_found', 'This item is not available for purchase.', 404);
        }

        $siteUrl = SiteUrlResolver::resolve();
        $mediaUrls = new MediaUrlHelper($this->pdo);
        $featuredUrl = null;
        if ($type->supportsFeaturedImage && ($entry->featuredImageId ?? 0) > 0) {
            $path = $mediaUrls->pathForId((int) $entry->featuredImageId);
            if ($path !== '') {
                $featuredUrl = MobileBootstrapService::absoluteUrl($siteUrl, $path);
            }
        }

        return [
            'id' => $entry->id,
            'slug' => $entry->slug,
            'title' => $entry->title,
            'excerpt' => trim($entry->seoDescription ?? ''),
            'featured_image_url' => $featuredUrl,
            'price_cents' => $product->priceCents,
            'price_formatted' => $product->formattedPrice(),
            'currency' => $product->currency,
            'sku' => $product->sku,
            'in_stock' => $this->products->hasStock($product, 1),
            'stock_qty' => $product->stockQty,
            'uses_stripe_price' => $product->stripePriceId !== null,
            'product_type_slug' => $type->slug,
            'url' => $siteUrl . '/' . rawurlencode($type->slug) . '/' . rawurlencode($entry->slug),
        ];
    }

    /**
     * @param list<array<string, mixed>> $linesInput
     * @return array<string, mixed>
     */
    public function quoteCart(array $linesInput, ?string $shipCountry, ?string $couponCode): array
    {
        $resolved = $this->resolveCart($linesInput, $shipCountry, $couponCode);

        return $this->cartPayload($resolved);
    }

    /**
     * @param list<array<string, mixed>> $linesInput
     * @return array{checkout_url: string, order_number: string, order_id: int}
     */
    public function startCheckout(array $linesInput, ?string $shipCountry, ?string $couponCode, ?int $customerUserId): array
    {
        $resolved = $this->resolveCart($linesInput, $shipCountry, $couponCode);
        if ($resolved['lines'] === []) {
            throw new MobileCommerceException('empty_cart', 'Your cart is empty.');
        }

        if ($this->commerce->needsCheckoutCountry() && ($resolved['ship_country'] ?? null) === null) {
            throw new MobileCommerceException(
                'country_required',
                'Select a shipping country before checkout.',
            );
        }

        foreach ($resolved['lines'] as $line) {
            if (!$this->products->hasStock($line->product, $line->quantity)) {
                throw new MobileCommerceException(
                    'out_of_stock',
                    sprintf('"%s" is out of stock.', $line->product->title),
                );
            }
            if ($line->product->stripePriceId !== null && $resolved['coupon_code'] !== null) {
                throw new MobileCommerceException(
                    'coupon_not_allowed',
                    'Remove the coupon before checking out Stripe Price ID products.',
                );
            }
        }

        $siteUrl = SiteUrlResolver::resolve();
        $result = $this->checkout->startCartCheckout(
            $resolved['lines'],
            $siteUrl,
            $resolved['totals'],
            $customerUserId,
        );
        if (!$result['ok']) {
            throw new MobileCommerceException('checkout_failed', $result['error']);
        }

        return [
            'checkout_url' => $result['redirect_url'],
            'order_number' => $result['order']->orderNumber,
            'order_id' => $result['order']->id,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listOrders(int $userId, string $email): array
    {
        $this->assertCommerceEnabled();
        $orders = $this->orders->listForCustomer($userId, $email);

        return array_map(fn (CommerceOrder $order): array => $this->orderSummary($order), $orders);
    }

    /**
     * @return array<string, mixed>
     */
    public function orderDetail(string $orderNumber, int $userId, string $email): array
    {
        $this->assertCommerceEnabled();
        $order = $this->orders->findByOrderNumberForCustomer($orderNumber, $userId, $email);
        if ($order === null) {
            throw new MobileCommerceException('not_found', 'Order not found.', 404);
        }

        return $this->orderDetailPayload($order);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listDigitalDownloads(int $userId, string $email): array
    {
        $this->assertCommerceEnabled();
        $siteUrl = SiteUrlResolver::resolve();
        $downloads = [];

        foreach ($this->orders->listForCustomer($userId, $email) as $order) {
            if ($order->status !== 'paid') {
                continue;
            }
            foreach ($this->digital->activeGrantsForOrder($order) as $grant) {
                $downloads[] = $this->digitalGrantPayload($grant, $order, $siteUrl);
            }
        }

        return $downloads;
    }

    /**
     * @return array<string, mixed>
     */
    public function commerceConfig(): array
    {
        $this->assertCommerceEnabled();
        $type = $this->requireProductType();
        $shippingZoneRepo = new ShippingZoneRepository($this->pdo);
        $taxRateRepo = new TaxRateRepository($this->pdo);
        $preferredCountries = array_merge(
            $shippingZoneRepo->allCountryCodes(),
            array_map(static fn ($r) => $r->countryCode, $taxRateRepo->listActive()),
        );

        return [
            'currency' => $this->commerce->defaultCurrency(),
            'product_type_slug' => $type->slug,
            'needs_checkout_country' => $this->commerce->needsCheckoutCountry(),
            'country_choices' => CommerceCountryCodes::forSelect($preferredCountries),
        ];
    }

    public static function formatMoney(int $cents, string $currency): string
    {
        $amount = $cents / 100;
        $symbol = match (strtolower($currency)) {
            'gbp' => '£',
            'eur' => '€',
            'usd' => '$',
            default => strtoupper($currency) . ' ',
        };

        return $symbol . number_format($amount, 2);
    }

    /**
     * @param list<array<string, mixed>> $linesInput
     * @return array{
     *   lines: list<CartLine>,
     *   subtotal_cents: int,
     *   currency: string,
     *   totals: OrderTotals,
     *   coupon_code: ?string,
     *   coupon_error: ?string,
     *   ship_country: ?string
     * }
     */
    private function resolveCart(array $linesInput, ?string $shipCountry, ?string $couponCode): array
    {
        $this->assertCommerceEnabled();
        $type = $this->requireProductType();
        $parsed = $this->parseLinesInput($linesInput);
        if ($parsed === []) {
            return [
                'lines' => [],
                'subtotal_cents' => 0,
                'currency' => $this->commerce->defaultCurrency(),
                'totals' => new OrderTotals(0, 0, 0, 0, 0, null, null),
                'coupon_code' => null,
                'coupon_error' => null,
                'ship_country' => $this->normalizeCountry($shipCountry),
            ];
        }

        $lines = [];
        $subtotal = 0;
        $currency = $this->commerce->defaultCurrency();
        $hasStripePrice = false;
        $shipCountry = $this->normalizeCountry($shipCountry);

        foreach ($parsed as $lineInput) {
            $entryId = $lineInput['entry_id'];
            $qty = $lineInput['quantity'];
            $entry = $this->entries->findById($entryId);
            if ($entry === null || $entry->contentTypeId !== $type->id || $entry->status !== 'published') {
                throw new MobileCommerceException('invalid_line', 'One or more cart items are no longer available.');
            }
            $valueMap = $this->values->valuesByFieldIdForEntry($entryId);
            $product = $this->products->resolvePublished($type, $entry, $valueMap);
            if ($product === null) {
                throw new MobileCommerceException('invalid_line', 'One or more cart items are no longer available.');
            }
            if (!$this->products->hasStock($product, $qty)) {
                throw new MobileCommerceException(
                    'out_of_stock',
                    sprintf('"%s" is not available in the requested quantity.', $product->title),
                );
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

        $couponError = null;
        $coupon = null;
        $couponCode = $this->normalizeCoupon($couponCode);
        if ($couponCode !== null) {
            if ($hasStripePrice) {
                $couponError = 'Coupons cannot be used with Stripe Price ID products.';
                $couponCode = null;
            } else {
                $validation = $this->coupons->validateForSubtotal($couponCode, $subtotal);
                if (!$validation['ok']) {
                    $couponError = $validation['error'];
                    $couponCode = null;
                } else {
                    $coupon = $validation['coupon'];
                }
            }
        }

        $totals = $this->totalsCalculator->calculate($subtotal, $coupon, $couponCode, $shipCountry);

        return [
            'lines' => $lines,
            'subtotal_cents' => $subtotal,
            'currency' => $currency,
            'totals' => $totals,
            'coupon_code' => $couponCode,
            'coupon_error' => $couponError,
            'ship_country' => $shipCountry,
        ];
    }

    /**
     * @param array{
     *   lines: list<CartLine>,
     *   subtotal_cents: int,
     *   currency: string,
     *   totals: OrderTotals,
     *   coupon_code: ?string,
     *   coupon_error: ?string,
     *   ship_country: ?string
     * } $resolved
     * @return array<string, mixed>
     */
    private function cartPayload(array $resolved): array
    {
        $siteUrl = SiteUrlResolver::resolve();
        $mediaUrls = new MediaUrlHelper($this->pdo);
        $lineItems = [];
        foreach ($resolved['lines'] as $line) {
            $product = $line->product;
            $featuredUrl = null;
            $entry = $this->entries->findById($product->entryId);
            if ($entry !== null && ($entry->featuredImageId ?? 0) > 0) {
                $path = $mediaUrls->pathForId((int) $entry->featuredImageId);
                if ($path !== '') {
                    $featuredUrl = MobileBootstrapService::absoluteUrl($siteUrl, $path);
                }
            }
            $lineItems[] = [
                'entry_id' => $product->entryId,
                'slug' => $product->entrySlug,
                'title' => $product->title,
                'quantity' => $line->quantity,
                'unit_price_cents' => $product->priceCents,
                'line_total_cents' => $line->lineTotalCents,
                'price_formatted' => $product->formattedPrice(),
                'featured_image_url' => $featuredUrl,
                'in_stock' => $this->products->hasStock($product, $line->quantity),
            ];
        }

        $totals = $resolved['totals'];

        return [
            'lines' => $lineItems,
            'subtotal_cents' => $resolved['subtotal_cents'],
            'currency' => $resolved['currency'],
            'totals' => [
                'subtotal_cents' => $totals->subtotalCents,
                'discount_cents' => $totals->discountCents,
                'tax_cents' => $totals->taxCents,
                'shipping_cents' => $totals->shippingCents,
                'total_cents' => $totals->totalCents,
                'total_formatted' => self::formatMoney($totals->totalCents, $resolved['currency']),
                'coupon_code' => $totals->couponCode,
                'shipping_label' => $totals->shippingLabel,
            ],
            'coupon_code' => $resolved['coupon_code'],
            'coupon_error' => $resolved['coupon_error'],
            'ship_country' => $resolved['ship_country'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    private function productFromRow(
        \App\Content\ContentType $type,
        array $row,
        string $siteUrl,
        MediaUrlHelper $mediaUrls,
    ): ?array {
        $entryId = (int) ($row['id'] ?? 0);
        if ($entryId < 1) {
            return null;
        }
        $entry = $this->entries->findById($entryId);
        if ($entry === null) {
            return null;
        }
        $valueMap = $this->values->valuesByFieldIdForEntry($entryId);
        $product = $this->products->resolvePublished($type, $entry, $valueMap);
        if ($product === null) {
            return null;
        }

        $featuredUrl = null;
        if ($type->supportsFeaturedImage) {
            $imageId = (int) ($row['featured_image_id'] ?? 0);
            if ($imageId > 0) {
                $path = $mediaUrls->pathForId($imageId);
                if ($path !== '') {
                    $featuredUrl = MobileBootstrapService::absoluteUrl($siteUrl, $path);
                }
            }
        }

        return [
            'id' => $entryId,
            'slug' => (string) ($row['slug'] ?? $entry->slug),
            'title' => (string) ($row['title'] ?? $entry->title),
            'excerpt' => trim((string) ($row['seo_description'] ?? '')),
            'featured_image_url' => $featuredUrl,
            'price_cents' => $product->priceCents,
            'price_formatted' => $product->formattedPrice(),
            'currency' => $product->currency,
            'in_stock' => $this->products->hasStock($product, 1),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function orderSummary(CommerceOrder $order): array
    {
        $downloadCount = $order->status === 'paid'
            ? count($this->digital->activeGrantsForOrder($order))
            : 0;

        return [
            'order_number' => $order->orderNumber,
            'status' => $order->status,
            'currency' => $order->currency,
            'total_cents' => $order->totalCents,
            'total_formatted' => self::formatMoney($order->totalCents, $order->currency),
            'item_count' => array_sum(array_map(static fn ($item) => $item->quantity, $order->items)),
            'download_count' => $downloadCount,
            'created_at' => $order->createdAt,
            'paid_at' => $order->paidAt,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function orderDetailPayload(CommerceOrder $order): array
    {
        $siteUrl = SiteUrlResolver::resolve();
        $items = [];
        foreach ($order->items as $item) {
            $items[] = [
                'title' => $item->title,
                'quantity' => $item->quantity,
                'unit_price_cents' => $item->unitPriceCents,
                'line_total_cents' => $item->lineTotalCents,
                'unit_price_formatted' => self::formatMoney($item->unitPriceCents, $order->currency),
                'line_total_formatted' => self::formatMoney($item->lineTotalCents, $order->currency),
            ];
        }

        $digitalDownloads = $order->status === 'paid'
            ? array_map(
                fn (DigitalGrant $grant): array => $this->digitalGrantPayload($grant, $order, $siteUrl),
                $this->digital->activeGrantsForOrder($order),
            )
            : [];

        return [
            'order_number' => $order->orderNumber,
            'status' => $order->status,
            'currency' => $order->currency,
            'subtotal_cents' => $order->subtotalCents,
            'discount_cents' => $order->discountCents,
            'tax_cents' => $order->taxCents,
            'shipping_cents' => $order->shippingCents,
            'total_cents' => $order->totalCents,
            'total_formatted' => self::formatMoney($order->totalCents, $order->currency),
            'coupon_code' => $order->couponCode,
            'shipping_label' => $order->shippingLabel,
            'created_at' => $order->createdAt,
            'paid_at' => $order->paidAt,
            'items' => $items,
            'digital_downloads' => $digitalDownloads,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function digitalGrantPayload(DigitalGrant $grant, CommerceOrder $order, string $siteUrl): array
    {
        $base = rtrim($siteUrl, '/');
        $payload = [
            'id' => $grant->id,
            'order_number' => $order->orderNumber,
            'label' => $grant->label,
            'delivery_type' => $grant->deliveryType,
            'access_url' => $base . '/commerce/access/' . $grant->accessToken,
            'content_entry_id' => $grant->contentEntryId,
        ];

        if ($grant->deliveryType === 'entry') {
            $payload['content_type_slug'] = (string) ($grant->payload['type_slug'] ?? '');
            $payload['entry_slug'] = (string) ($grant->payload['entry_slug'] ?? '');
        }

        return $payload;
    }

    /**
     * @param list<array<string, mixed>> $linesInput
     * @return list<array{entry_id: int, quantity: int}>
     */
    private function parseLinesInput(array $linesInput): array
    {
        if (count($linesInput) > self::MAX_CART_LINES) {
            throw new MobileCommerceException(
                'cart_too_large',
                sprintf('Cart cannot contain more than %d line items.', self::MAX_CART_LINES),
            );
        }

        $out = [];
        foreach ($linesInput as $line) {
            if (!is_array($line)) {
                continue;
            }
            $entryId = isset($line['entry_id']) ? (int) $line['entry_id'] : 0;
            $qty = isset($line['quantity']) ? (int) $line['quantity'] : 0;
            if ($entryId < 1 || $qty < 1) {
                continue;
            }
            $out[] = [
                'entry_id' => $entryId,
                'quantity' => min(99, $qty),
            ];
        }

        return $out;
    }

    private function normalizeCountry(?string $country): ?string
    {
        if ($country === null || trim($country) === '') {
            return null;
        }
        $code = strtoupper(trim($country));

        return preg_match('/^[A-Z]{2}$/', $code) === 1 ? $code : null;
    }

    private function normalizeCoupon(?string $couponCode): ?string
    {
        if ($couponCode === null) {
            return null;
        }
        $couponCode = trim($couponCode);

        return $couponCode !== '' ? $couponCode : null;
    }

    private function assertCommerceEnabled(): void
    {
        if (!MobileSettings::enabled()) {
            throw new MobileCommerceException(
                'mobile_disabled',
                'Mobile app access is disabled for this site.',
                403,
            );
        }
        if (!$this->commerce->isEnabled()) {
            throw new MobileCommerceException('commerce_disabled', 'Commerce is not enabled on this site.', 404);
        }
    }

    private function requireProductType(): \App\Content\ContentType
    {
        $type = $this->types->findBySlug($this->commerce->productTypeSlug());
        if ($type === null || !$type->hasPublicRoute) {
            throw new MobileCommerceException('commerce_misconfigured', 'Product content type is not configured.', 404);
        }

        return $type;
    }
}
