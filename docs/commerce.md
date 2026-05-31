# Commerce (content-type products + Stripe)

Core commerce sells **published content entries** from a configured product content type via **Stripe Checkout**.

## Setup

1. Run migrations: `composer migrate`
2. **Tools → Blueprints** — import **Product store** (creates `product` type and sample entry), or add the fields manually to your own type.
3. **Admin → Commerce → Commerce settings**:
   - Enable commerce
   - Set product content type slug (default `product`)
   - Set currency (`gbp`, `usd`, …)
   - Optional: order notification email, customer confirmation emails, inventory tracking
   - Optional: flat tax rate, flat shipping + free-shipping threshold + allowed countries
   - Add Stripe secret, publishable, and webhook signing keys
4. **Admin → Coupons** — create discount codes for cart checkout
5. In Stripe Dashboard, add webhook endpoint: `https://your-site/commerce/stripe/webhook`  
   Events: `checkout.session.completed`, `checkout.session.expired`

## Product field convention

On the product content type:

| Field key | Type | Purpose |
|-----------|------|---------|
| `price_cents` | number | Price in minor units (1999 = £19.99). Required unless `stripe_price_id` is set. |
| `purchasable` | boolean | Set to off to hide buy button while keeping the entry public. |
| `stripe_price_id` | text | Optional Stripe Price ID (`price_…`) — overrides ad-hoc `price_cents`. |
| `sku` | text | Optional SKU stored on order line metadata. |
| `stock_qty` | number | Optional stock count. Empty = unlimited. Decremented on paid orders when inventory tracking is enabled. |

**Note:** Coupons and local tax/shipping totals apply to `price_cents` lines. Products using `stripe_price_id` only charge via Stripe’s price; coupons are disabled when such items are in the cart.

## Storefront

When commerce is enabled, **`/shop`** lists published products from the configured product type with **live prices** from commerce field conventions. The theme header includes **Shop** and **Cart (n)** links.

Published product entries show **Buy now** and **Add to cart** when in stock (default theme). Product archive pages (`/{product-type-slug}`) also show commerce prices and **Add to cart** on catalog cards when the type is the configured product type.

- **Buy now** — POST `/commerce/checkout` (single item, Stripe Checkout)
- **Cart** — GET `/commerce/cart`; add/update via POST `/commerce/cart/add` and `/commerce/cart/update`
- **Coupon** — POST `/commerce/cart/coupon` (apply or remove)
- **Cart checkout** — POST `/commerce/cart/checkout` (multi-item Stripe Checkout)

All checkout POST routes require CSRF tokens.

When shipping is enabled, Stripe Checkout collects a **shipping address** (allowed countries configurable in admin).

Success URL: `/commerce/checkout/success?session_id=…` — triggers fulfillment (inventory + emails + coupon redemption) if the webhook has not already.

## Order history

Orders are linked to **PHPAuth accounts** via `customer_user_id` when the buyer is logged in at checkout, or automatically after payment when the Stripe email matches an existing account.

- **Logged-in customers** — GET `/commerce/orders` lists orders by account ID and email; GET `/commerce/orders/{order_number}` shows full detail.
- **Guests** — GET/POST `/commerce/orders/lookup` with order number + email.

## Fulfillment

When an order is marked **paid** (webhook or success page):

1. **Inventory** — if tracking is enabled and the product type has `stock_qty`, quantities are decremented once per order.
2. **Emails** — if enabled, sends customer confirmation (with totals breakdown and ship-to address) and optional admin notification via PHP `mail()`.
3. **Coupons** — increments coupon use count once per order.

**Refunds** — Admin → order detail → **Refund order** calls Stripe Refund API, marks order `refunded`, and restores inventory when applicable.

## Admin

- **Orders** — list, detail, **Export CSV**
- **Coupons** — CRUD for `cms_commerce_coupons`
- **Commerce settings** — enable flag, type slug, currency, shop page title, email/inventory/tax/shipping, Stripe keys

Permission: `manage_commerce` (super_admin and admin roles).

## Architecture

- `App\Commerce\Catalog\ShopCatalogPage` — core `/shop` product catalog
- `App\Commerce\Product\ProductCatalogEnricher` — live prices on catalog cards
- `App\Commerce\Customer\CommerceCustomerLinker` — links orders to phpauth_users
- `App\Commerce\Product\ProductResolver` — maps entry + field values → `PurchasableProduct`
- `App\Commerce\Cart\CartService` / `CartResolver` — session cart + coupon code
- `App\Commerce\Pricing\OrderTotalsCalculator` — subtotal, discount, tax, shipping, total
- `App\Commerce\Coupon\CouponService` — validate and redeem coupons
- `App\Commerce\Payment\StripeCheckoutService` — Checkout Session + order creation
- `App\Commerce\Payment\StripeWebhookHandler` — marks orders paid/cancelled, saves shipping address
- `App\Commerce\Order\OrderFulfillmentService` — inventory + emails + coupon redemption
- `App\Commerce\Payment\StripeRefundService` — admin refunds
- `App\Commerce\Order\CommerceOrderCsvExporter` — admin CSV export
