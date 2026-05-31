<?php

declare(strict_types=1);

use App\Access\PermissionSlug;
use App\Commerce\CommerceSettings;
use App\Commerce\Coupon\CouponRepository;
use App\Commerce\Coupon\CouponService;
use App\Commerce\Digital\DigitalFulfillmentService;
use App\Commerce\Digital\DigitalGrantRepository;
use App\Commerce\Inventory\InventoryService;
use App\Commerce\Inventory\LowStockReportService;
use App\Commerce\Order\CommerceOrderCsvExporter;
use App\Commerce\Order\CommerceOrderRepository;
use App\Commerce\Order\OrderFulfillmentService;
use App\Commerce\Order\OrderListFilter;
use App\Commerce\Payment\StripeRefundService;
use App\Commerce\Product\ProductResolver;
use App\Commerce\Shipping\ShippingZoneRepository;
use App\Commerce\Tax\TaxRateRepository;
use App\Content\ContentEntryRepository;
use App\Content\ContentEntryValueRepository;
use App\Content\ContentFieldRepository;
use App\Content\ContentTypeRepository;
use App\Flash;
use App\Http\Middleware\RequireCmsStaff;
use App\Http\Middleware\RequirePermission;
use App\Security\SystemApiKeyRepository;
use App\Settings;
use App\Settings\SettingsRepository;
use PHPAuth\Auth;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Exception\HttpNotFoundException;
use Slim\Routing\RouteContext;
use Slim\Views\Twig;

/**
 * @param callable(): array<string, mixed> $viewData
 */
return static function (App $app, Twig $twig, Auth $auth, \PDO $pdo, callable $viewData): void {
    $middleware = new RequireCmsStaff($auth, $pdo);
    $perm = new RequirePermission($pdo, [PermissionSlug::MANAGE_COMMERCE]);
    $commerce = new CommerceSettings($pdo);
    $orders = new CommerceOrderRepository($pdo);
    $settingsRepo = new SettingsRepository($pdo);
    $apiKeys = new SystemApiKeyRepository($pdo);
    $types = new ContentTypeRepository($pdo);
    $entries = new ContentEntryRepository($pdo);
    $fields = new ContentFieldRepository($pdo);
    $values = new ContentEntryValueRepository($pdo);
    $products = new ProductResolver($pdo, $commerce, $fields);
    $inventory = new InventoryService($pdo, $commerce, $types, $values, $products);
    $couponRepo = new CouponRepository($pdo);
    $coupons = new CouponService($couponRepo);
    $digitalGrants = new DigitalGrantRepository($pdo);
    $digital = DigitalFulfillmentService::factory($pdo, $commerce, $types, $fields, $values, $entries, $orders);
    $fulfillment = new OrderFulfillmentService($pdo, $commerce, $orders, $inventory, $coupons, $digital);
    $refunds = new StripeRefundService($commerce, $orders);
    $shippingZones = new ShippingZoneRepository($pdo);
    $taxRates = new TaxRateRepository($pdo);
    $lowStock = new LowStockReportService($pdo, $commerce, $types, $entries, $values, $products);

    $adminContext = static fn (): array => array_merge($viewData(), []);
    $withCmsUser = static function (Request $request, array $data): array {
        /** @var array<string, mixed> $cmsUser */
        $cmsUser = $request->getAttribute('cms_user') ?? [];

        return array_merge($data, ['cms_user' => $cmsUser]);
    };

    $mask = static function (string $v): string {
        $v = trim($v);
        $len = strlen($v);
        if ($len <= 8) {
            return str_repeat('*', max(6, $len));
        }

        return str_repeat('*', $len - 4) . substr($v, -4);
    };

    $stripeKeys = static function () use ($apiKeys, $mask): array {
        $rows = $apiKeys->listByProvider(CommerceSettings::STRIPE_PROVIDER);
        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row['key_name']] = [
                'stored' => trim((string) $row['key_value']) !== '',
                'masked' => $mask((string) $row['key_value']),
            ];
        }

        return [
            'secret' => $map[CommerceSettings::STRIPE_SECRET] ?? ['stored' => false, 'masked' => ''],
            'publishable' => $map[CommerceSettings::STRIPE_PUBLISHABLE] ?? ['stored' => false, 'masked' => ''],
            'webhook' => $map[CommerceSettings::STRIPE_WEBHOOK] ?? ['stored' => false, 'masked' => ''],
        ];
    };

    $app->group('/admin/commerce', function (\Slim\Routing\RouteCollectorProxy $group) use (
        $twig,
        $adminContext,
        $withCmsUser,
        $commerce,
        $orders,
        $settingsRepo,
        $apiKeys,
        $pdo,
        $mask,
        $stripeKeys,
        $viewData,
        $refunds,
        $fulfillment,
        $couponRepo,
        $shippingZones,
        $taxRates,
        $lowStock,
        $types,
        $digitalGrants,
        $digital
    ): void {
        $group->get('/orders', function (Request $request, Response $response) use ($twig, $adminContext, $withCmsUser, $orders): Response {
            $filter = OrderListFilter::fromQueryParams($request->getQueryParams());
            $orderRows = $filter->isActive() ? $orders->listFiltered($filter) : $orders->listRecent(200);
            $exportQuery = array_filter([
                'status' => $filter->status,
                'email' => $filter->email,
                'order_number' => $filter->orderNumber,
                'date_from' => $filter->dateFrom,
                'date_to' => $filter->dateTo,
            ], static fn ($v): bool => $v !== null && $v !== '');

            return $twig->render($response, 'admin/commerce/orders.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'commerce_orders',
                'orders' => $orderRows,
                'order_filter' => $filter,
                'order_export_query' => $exportQuery,
            ])));
        })->setName('admin.commerce.orders');

        $group->get('/orders/export.csv', function (Request $request, Response $response) use ($orders): Response {
            $filter = OrderListFilter::fromQueryParams($request->getQueryParams());
            $filter = new OrderListFilter(
                $filter->status,
                $filter->email,
                $filter->orderNumber,
                $filter->dateFrom,
                $filter->dateTo,
                5000,
            );
            $rows = $filter->isActive() ? $orders->listFiltered($filter) : $orders->listRecent(5000);
            $csv = (new CommerceOrderCsvExporter())->export($rows);
            $filename = 'commerce-orders-' . gmdate('Y-m-d') . '.csv';
            $response->getBody()->write($csv);

            return $response
                ->withHeader('Content-Type', 'text/csv; charset=utf-8')
                ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');
        })->setName('admin.commerce.orders.export');

        $group->get('/orders/{id}', function (Request $request, Response $response, array $args) use ($twig, $adminContext, $withCmsUser, $orders, $digitalGrants, $viewData): Response {
            $id = (int) ($args['id'] ?? 0);
            $order = $orders->findById($id);
            if ($order === null) {
                throw new HttpNotFoundException($request);
            }
            $vd = $viewData();
            $siteUrl = rtrim((string) ($vd['site_url'] ?? ''), '/');

            return $twig->render($response, 'admin/commerce/order_show.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'commerce_orders',
                'order' => $order,
                'digital_grants' => $digitalGrants->forOrder($id, false),
                'digital_access_base' => $siteUrl . '/commerce/access/',
            ])));
        })->setName('admin.commerce.orders.show');

        $group->get('/settings', function (Request $request, Response $response) use (
            $twig,
            $adminContext,
            $withCmsUser,
            $commerce,
            $stripeKeys,
            $viewData
        ): Response {
            $vd = $viewData();
            $siteUrl = rtrim((string) ($vd['site_url'] ?? ''), '/');

            return $twig->render($response, 'admin/commerce/settings.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'commerce_settings',
                'commerce_enabled' => $commerce->isEnabled(),
                'commerce_product_type_slug' => $commerce->productTypeSlug(),
                'commerce_currency' => $commerce->defaultCurrency(),
                'commerce_notify_email' => $commerce->notifyEmail(),
                'commerce_send_order_emails' => $commerce->sendOrderEmails(),
                'commerce_track_inventory' => $commerce->trackInventory(),
                'commerce_tax_enabled' => $commerce->taxEnabled(),
                'commerce_tax_mode' => $commerce->taxMode(),
                'commerce_tax_rate_bps' => $commerce->taxRateBps(),
                'commerce_use_shipping_zones' => $commerce->useShippingZones(),
                'commerce_low_stock_threshold' => $commerce->lowStockThreshold(),
                'commerce_shipping_enabled' => $commerce->shippingEnabled(),
                'commerce_shipping_flat_cents' => $commerce->shippingFlatCents(),
                'commerce_free_shipping_min_cents' => $commerce->freeShippingMinCents(),
                'commerce_shipping_label' => $commerce->shippingLabel(),
                'commerce_shipping_countries' => implode(', ', $commerce->shippingCountries()),
                'commerce_shop_title' => $commerce->shopTitle(),
                'commerce_shop_description' => $commerce->shopDescription(),
                'stripe_keys' => $stripeKeys(),
                'webhook_url' => $siteUrl . '/commerce/stripe/webhook',
            ])));
        })->setName('admin.commerce.settings');

        $group->post('/settings', function (Request $request, Response $response) use ($settingsRepo, $pdo): Response {
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $enabled = !empty($body['commerce_enabled']) ? '1' : '0';
            $typeSlug = isset($body['commerce_product_type_slug']) && is_string($body['commerce_product_type_slug'])
                ? strtolower(trim($body['commerce_product_type_slug']))
                : 'product';
            if ($typeSlug === '' || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $typeSlug) !== 1) {
                Flash::set('error', 'Product content type slug must be lowercase kebab-case.');

                return $response->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.commerce.settings'))->withStatus(302);
            }
            $currency = isset($body['commerce_currency']) && is_string($body['commerce_currency'])
                ? strtolower(trim($body['commerce_currency']))
                : 'gbp';
            if (preg_match('/^[a-z]{3}$/', $currency) !== 1) {
                Flash::set('error', 'Currency must be a 3-letter ISO code (e.g. gbp).');

                return $response->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.commerce.settings'))->withStatus(302);
            }

            $settingsRepo->upsert(CommerceSettings::SETTING_ENABLED, $enabled);
            $settingsRepo->upsert(CommerceSettings::SETTING_PRODUCT_TYPE_SLUG, $typeSlug);
            $settingsRepo->upsert(CommerceSettings::SETTING_CURRENCY, $currency);
            $settingsRepo->upsert(CommerceSettings::SETTING_NOTIFY_EMAIL, isset($body['commerce_notify_email']) && is_string($body['commerce_notify_email']) ? trim($body['commerce_notify_email']) : '');
            $settingsRepo->upsert(CommerceSettings::SETTING_SEND_ORDER_EMAILS, !empty($body['commerce_send_order_emails']) ? '1' : '0');
            $settingsRepo->upsert(CommerceSettings::SETTING_TRACK_INVENTORY, !empty($body['commerce_track_inventory']) ? '1' : '0');
            $settingsRepo->upsert(CommerceSettings::SETTING_TAX_ENABLED, !empty($body['commerce_tax_enabled']) ? '1' : '0');
            $taxMode = isset($body['commerce_tax_mode']) && is_string($body['commerce_tax_mode']) ? strtolower(trim($body['commerce_tax_mode'])) : CommerceSettings::TAX_MODE_FLAT;
            if (!in_array($taxMode, [CommerceSettings::TAX_MODE_FLAT, CommerceSettings::TAX_MODE_COUNTRY, CommerceSettings::TAX_MODE_STRIPE], true)) {
                $taxMode = CommerceSettings::TAX_MODE_FLAT;
            }
            $settingsRepo->upsert(CommerceSettings::SETTING_TAX_MODE, $taxMode);
            $taxBps = isset($body['commerce_tax_rate_bps']) ? (int) $body['commerce_tax_rate_bps'] : 0;
            $settingsRepo->upsert(CommerceSettings::SETTING_TAX_RATE_BPS, (string) max(0, min(10000, $taxBps)));
            $settingsRepo->upsert(CommerceSettings::SETTING_USE_SHIPPING_ZONES, !empty($body['commerce_use_shipping_zones']) ? '1' : '0');
            $lowStock = isset($body['commerce_low_stock_threshold']) ? max(0, (int) $body['commerce_low_stock_threshold']) : 5;
            $settingsRepo->upsert(CommerceSettings::SETTING_LOW_STOCK_THRESHOLD, (string) $lowStock);
            $settingsRepo->upsert(CommerceSettings::SETTING_SHIPPING_ENABLED, !empty($body['commerce_shipping_enabled']) ? '1' : '0');
            $shipFlat = isset($body['commerce_shipping_flat_cents']) ? max(0, (int) $body['commerce_shipping_flat_cents']) : 0;
            $settingsRepo->upsert(CommerceSettings::SETTING_SHIPPING_FLAT_CENTS, (string) $shipFlat);
            $freeMin = isset($body['commerce_free_shipping_min_cents']) ? max(0, (int) $body['commerce_free_shipping_min_cents']) : 0;
            $settingsRepo->upsert(CommerceSettings::SETTING_FREE_SHIPPING_MIN_CENTS, (string) $freeMin);
            $shipLabel = isset($body['commerce_shipping_label']) && is_string($body['commerce_shipping_label'])
                ? trim($body['commerce_shipping_label']) : 'Standard shipping';
            $settingsRepo->upsert(CommerceSettings::SETTING_SHIPPING_LABEL, $shipLabel !== '' ? $shipLabel : 'Standard shipping');
            $shipCountries = isset($body['commerce_shipping_countries']) && is_string($body['commerce_shipping_countries'])
                ? strtoupper(preg_replace('/[^A-Za-z,\s]/', '', $body['commerce_shipping_countries']) ?? '')
                : 'GB';
            $settingsRepo->upsert(CommerceSettings::SETTING_SHIPPING_COUNTRIES, $shipCountries !== '' ? $shipCountries : 'GB');
            $shopTitle = isset($body['commerce_shop_title']) && is_string($body['commerce_shop_title'])
                ? trim($body['commerce_shop_title']) : 'Shop';
            $settingsRepo->upsert(CommerceSettings::SETTING_SHOP_TITLE, $shopTitle !== '' ? $shopTitle : 'Shop');
            $shopDesc = isset($body['commerce_shop_description']) && is_string($body['commerce_shop_description'])
                ? trim($body['commerce_shop_description']) : '';
            $settingsRepo->upsert(CommerceSettings::SETTING_SHOP_DESCRIPTION, $shopDesc);
            Settings::reload($pdo);
            Flash::set('success', 'Commerce settings saved.');

            return $response->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.commerce.settings'))->withStatus(302);
        })->setName('admin.commerce.settings.save');

        $group->post('/settings/stripe', function (Request $request, Response $response) use ($apiKeys, $pdo): Response {
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $back = RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.commerce.settings');

            $saveKey = static function (string $name, string $field, bool $clearField) use ($apiKeys, $body): void {
                if (!empty($body[$clearField])) {
                    $apiKeys->deleteByProviderAndName(CommerceSettings::STRIPE_PROVIDER, $name);

                    return;
                }
                $value = isset($body[$field]) && is_string($body[$field]) ? trim($body[$field]) : '';
                if ($value !== '') {
                    $apiKeys->upsert(CommerceSettings::STRIPE_PROVIDER, $name, $value);
                }
            };

            $saveKey(CommerceSettings::STRIPE_SECRET, 'stripe_secret_key', 'stripe_secret_clear');
            $saveKey(CommerceSettings::STRIPE_PUBLISHABLE, 'stripe_publishable_key', 'stripe_publishable_clear');
            $saveKey(CommerceSettings::STRIPE_WEBHOOK, 'stripe_webhook_secret', 'stripe_webhook_clear');

            Flash::set('success', 'Stripe keys updated.');

            return $response->withHeader('Location', $back)->withStatus(302);
        })->setName('admin.commerce.settings.stripe');

        $group->post('/orders/{id}/refund', function (Request $request, Response $response, array $args) use ($orders, $refunds, $fulfillment): Response {
            $id = (int) ($args['id'] ?? 0);
            $order = $orders->findById($id);
            $back = RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.commerce.orders.show', ['id' => $id]);
            if ($order === null) {
                throw new HttpNotFoundException($request);
            }

            $result = $refunds->refundOrder($id);
            if (!$result['ok']) {
                Flash::set('error', $result['error']);

                return $response->withHeader('Location', $back)->withStatus(302);
            }
            $fulfillment->restoreInventoryIfRefunded($id);
            Flash::set('success', 'Order refunded via Stripe. Digital access links were revoked.');

            return $response->withHeader('Location', $back)->withStatus(302);
        })->setName('admin.commerce.orders.refund');

        $group->post('/orders/{id}/digital/resend', function (Request $request, Response $response, array $args) use ($orders, $fulfillment): Response {
            $id = (int) ($args['id'] ?? 0);
            $back = RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.commerce.orders.show', ['id' => $id]);
            $order = $orders->findById($id);
            if ($order === null) {
                throw new HttpNotFoundException($request);
            }
            if (!$fulfillment->resendDeliveryEmail($id)) {
                Flash::set('error', 'Could not resend delivery email. Order must be paid with a customer email on file.');

                return $response->withHeader('Location', $back)->withStatus(302);
            }
            Flash::set('success', 'Delivery email resent with current download links.');

            return $response->withHeader('Location', $back)->withStatus(302);
        })->setName('admin.commerce.orders.digital.resend');

        $group->post('/orders/{id}/digital/{grantId}/revoke', function (Request $request, Response $response, array $args) use ($orders, $digitalGrants): Response {
            $orderId = (int) ($args['id'] ?? 0);
            $grantId = (int) ($args['grantId'] ?? 0);
            $back = RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.commerce.orders.show', ['id' => $orderId]);
            $order = $orders->findById($orderId);
            if ($order === null) {
                throw new HttpNotFoundException($request);
            }
            $grant = $digitalGrants->findById($grantId);
            if ($grant === null || $grant->orderId !== $orderId) {
                Flash::set('error', 'Digital grant not found for this order.');

                return $response->withHeader('Location', $back)->withStatus(302);
            }
            $digitalGrants->revoke($grantId);
            Flash::set('success', 'Download link revoked.');

            return $response->withHeader('Location', $back)->withStatus(302);
        })->setName('admin.commerce.orders.digital.revoke');

        $group->post('/orders/{id}/digital/{grantId}/regenerate', function (Request $request, Response $response, array $args) use ($orders, $digitalGrants): Response {
            $orderId = (int) ($args['id'] ?? 0);
            $grantId = (int) ($args['grantId'] ?? 0);
            $back = RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.commerce.orders.show', ['id' => $orderId]);
            $order = $orders->findById($orderId);
            if ($order === null) {
                throw new HttpNotFoundException($request);
            }
            $grant = $digitalGrants->findById($grantId);
            if ($grant === null || $grant->orderId !== $orderId) {
                Flash::set('error', 'Digital grant not found for this order.');

                return $response->withHeader('Location', $back)->withStatus(302);
            }
            $digitalGrants->regenerateToken($grantId);
            Flash::set('success', 'New access token issued. Previous links no longer work.');

            return $response->withHeader('Location', $back)->withStatus(302);
        })->setName('admin.commerce.orders.digital.regenerate');

        $group->get('/coupons', function (Request $request, Response $response) use ($twig, $adminContext, $withCmsUser, $couponRepo): Response {
            return $twig->render($response, 'admin/commerce/coupons.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'commerce_coupons',
                'coupons' => $couponRepo->listAll(),
            ])));
        })->setName('admin.commerce.coupons');

        $group->get('/coupons/new', function (Request $request, Response $response) use ($twig, $adminContext, $withCmsUser): Response {
            return $twig->render($response, 'admin/commerce/coupon_form.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'commerce_coupons',
                'coupon' => null,
            ])));
        })->setName('admin.commerce.coupons.new');

        $group->post('/coupons', function (Request $request, Response $response) use ($couponRepo): Response {
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $code = isset($body['code']) && is_string($body['code']) ? strtoupper(trim($body['code'])) : '';
            if ($code === '' || preg_match('/^[A-Z0-9_-]{2,64}$/', $code) !== 1) {
                Flash::set('error', 'Coupon code must be 2–64 letters, numbers, underscores, or hyphens.');

                return $response->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.commerce.coupons.new'))->withStatus(302);
            }
            if ($couponRepo->findByCode($code) !== null) {
                Flash::set('error', 'That coupon code already exists.');

                return $response->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.commerce.coupons.new'))->withStatus(302);
            }
            $type = isset($body['discount_type']) && $body['discount_type'] === 'percent' ? 'percent' : 'fixed';
            $amount = isset($body['amount']) ? (int) $body['amount'] : 0;
            if ($amount < 1 || ($type === 'percent' && $amount > 100)) {
                Flash::set('error', 'Invalid discount amount.');

                return $response->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.commerce.coupons.new'))->withStatus(302);
            }
            $expires = isset($body['expires_at']) && is_string($body['expires_at']) && trim($body['expires_at']) !== ''
                ? trim($body['expires_at']) : null;
            $couponRepo->create([
                'code' => $code,
                'discount_type' => $type,
                'amount' => $amount,
                'min_subtotal_cents' => isset($body['min_subtotal_cents']) ? max(0, (int) $body['min_subtotal_cents']) : 0,
                'max_uses' => isset($body['max_uses']) && $body['max_uses'] !== '' ? max(1, (int) $body['max_uses']) : null,
                'active' => !empty($body['active']),
                'expires_at' => $expires,
            ]);
            Flash::set('success', 'Coupon created.');

            return $response->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.commerce.coupons'))->withStatus(302);
        })->setName('admin.commerce.coupons.create');

        $group->get('/coupons/{id}/edit', function (Request $request, Response $response, array $args) use ($twig, $adminContext, $withCmsUser, $couponRepo): Response {
            $id = (int) ($args['id'] ?? 0);
            $coupon = $couponRepo->findById($id);
            if ($coupon === null) {
                throw new HttpNotFoundException($request);
            }

            return $twig->render($response, 'admin/commerce/coupon_form.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'commerce_coupons',
                'coupon' => $coupon,
            ])));
        })->setName('admin.commerce.coupons.edit');

        $group->post('/coupons/{id}', function (Request $request, Response $response, array $args) use ($couponRepo): Response {
            $id = (int) ($args['id'] ?? 0);
            $coupon = $couponRepo->findById($id);
            $back = RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.commerce.coupons.edit', ['id' => $id]);
            if ($coupon === null) {
                throw new HttpNotFoundException($request);
            }
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $type = isset($body['discount_type']) && $body['discount_type'] === 'percent' ? 'percent' : 'fixed';
            $amount = isset($body['amount']) ? (int) $body['amount'] : 0;
            if ($amount < 1 || ($type === 'percent' && $amount > 100)) {
                Flash::set('error', 'Invalid discount amount.');

                return $response->withHeader('Location', $back)->withStatus(302);
            }
            $expires = isset($body['expires_at']) && is_string($body['expires_at']) && trim($body['expires_at']) !== ''
                ? trim($body['expires_at']) : null;
            $couponRepo->update($id, [
                'discount_type' => $type,
                'amount' => $amount,
                'min_subtotal_cents' => isset($body['min_subtotal_cents']) ? max(0, (int) $body['min_subtotal_cents']) : 0,
                'max_uses' => isset($body['max_uses']) && $body['max_uses'] !== '' ? max(1, (int) $body['max_uses']) : null,
                'active' => !empty($body['active']),
                'expires_at' => $expires,
            ]);
            Flash::set('success', 'Coupon updated.');

            return $response->withHeader('Location', $back)->withStatus(302);
        })->setName('admin.commerce.coupons.update');

        $group->post('/coupons/{id}/delete', function (Request $request, Response $response, array $args) use ($couponRepo): Response {
            $id = (int) ($args['id'] ?? 0);
            $couponRepo->delete($id);
            Flash::set('success', 'Coupon deleted.');

            return $response->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.commerce.coupons'))->withStatus(302);
        })->setName('admin.commerce.coupons.delete');

        $group->get('/shipping-zones', function (Request $request, Response $response) use ($twig, $adminContext, $withCmsUser, $shippingZones): Response {
            return $twig->render($response, 'admin/commerce/shipping_zones.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'commerce_shipping',
                'shipping_zones' => $shippingZones->listAll(),
            ])));
        })->setName('admin.commerce.shipping_zones');

        $group->get('/shipping-zones/new', function (Request $request, Response $response) use ($twig, $adminContext, $withCmsUser): Response {
            return $twig->render($response, 'admin/commerce/shipping_zone_form.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'commerce_shipping',
                'zone' => null,
            ])));
        })->setName('admin.commerce.shipping_zones.new');

        $group->post('/shipping-zones', function (Request $request, Response $response) use ($shippingZones): Response {
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $name = isset($body['name']) && is_string($body['name']) ? trim($body['name']) : '';
            $label = isset($body['label']) && is_string($body['label']) ? trim($body['label']) : '';
            if ($name === '' || $label === '') {
                Flash::set('error', 'Name and checkout label are required.');

                return $response->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.commerce.shipping_zones.new'))->withStatus(302);
            }
            $shippingZones->create([
                'name' => $name,
                'label' => $label,
                'price_cents' => isset($body['price_cents']) ? (int) $body['price_cents'] : 0,
                'free_shipping_min_cents' => isset($body['free_shipping_min_cents']) ? (int) $body['free_shipping_min_cents'] : 0,
                'countries' => $body['countries'] ?? [],
                'sort_order' => isset($body['sort_order']) ? (int) $body['sort_order'] : 0,
                'is_active' => !empty($body['is_active']),
            ]);
            Flash::set('success', 'Shipping zone created.');

            return $response->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.commerce.shipping_zones'))->withStatus(302);
        })->setName('admin.commerce.shipping_zones.create');

        $group->get('/shipping-zones/{id}/edit', function (Request $request, Response $response, array $args) use ($twig, $adminContext, $withCmsUser, $shippingZones): Response {
            $zone = $shippingZones->findById((int) ($args['id'] ?? 0));
            if ($zone === null) {
                throw new HttpNotFoundException($request);
            }

            return $twig->render($response, 'admin/commerce/shipping_zone_form.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'commerce_shipping',
                'zone' => $zone,
            ])));
        })->setName('admin.commerce.shipping_zones.edit');

        $group->post('/shipping-zones/{id}', function (Request $request, Response $response, array $args) use ($shippingZones): Response {
            $id = (int) ($args['id'] ?? 0);
            $zone = $shippingZones->findById($id);
            $back = RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.commerce.shipping_zones.edit', ['id' => $id]);
            if ($zone === null) {
                throw new HttpNotFoundException($request);
            }
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $shippingZones->update($id, [
                'name' => isset($body['name']) && is_string($body['name']) ? trim($body['name']) : $zone->name,
                'label' => isset($body['label']) && is_string($body['label']) ? trim($body['label']) : $zone->label,
                'price_cents' => isset($body['price_cents']) ? (int) $body['price_cents'] : $zone->priceCents,
                'free_shipping_min_cents' => isset($body['free_shipping_min_cents']) ? (int) $body['free_shipping_min_cents'] : $zone->freeShippingMinCents,
                'countries' => $body['countries'] ?? $zone->countries,
                'sort_order' => isset($body['sort_order']) ? (int) $body['sort_order'] : $zone->sortOrder,
                'is_active' => !empty($body['is_active']),
            ]);
            Flash::set('success', 'Shipping zone updated.');

            return $response->withHeader('Location', $back)->withStatus(302);
        })->setName('admin.commerce.shipping_zones.update');

        $group->post('/shipping-zones/{id}/delete', function (Request $request, Response $response, array $args) use ($shippingZones): Response {
            $shippingZones->delete((int) ($args['id'] ?? 0));
            Flash::set('success', 'Shipping zone deleted.');

            return $response->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.commerce.shipping_zones'))->withStatus(302);
        })->setName('admin.commerce.shipping_zones.delete');

        $group->get('/tax-rates', function (Request $request, Response $response) use ($twig, $adminContext, $withCmsUser, $taxRates): Response {
            return $twig->render($response, 'admin/commerce/tax_rates.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'commerce_tax',
                'tax_rates' => $taxRates->listAll(),
            ])));
        })->setName('admin.commerce.tax_rates');

        $group->get('/tax-rates/new', function (Request $request, Response $response) use ($twig, $adminContext, $withCmsUser): Response {
            return $twig->render($response, 'admin/commerce/tax_rate_form.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'commerce_tax',
                'tax_rate' => null,
            ])));
        })->setName('admin.commerce.tax_rates.new');

        $group->post('/tax-rates', function (Request $request, Response $response) use ($taxRates): Response {
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            try {
                $taxRates->create([
                    'country_code' => isset($body['country_code']) && is_string($body['country_code']) ? $body['country_code'] : '',
                    'label' => isset($body['label']) && is_string($body['label']) ? trim($body['label']) : '',
                    'rate_bps' => isset($body['rate_bps']) ? (int) $body['rate_bps'] : 0,
                    'sort_order' => isset($body['sort_order']) ? (int) $body['sort_order'] : 0,
                    'is_active' => !empty($body['is_active']),
                ]);
            } catch (\InvalidArgumentException) {
                Flash::set('error', 'Country code must be a 2-letter ISO code.');

                return $response->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.commerce.tax_rates.new'))->withStatus(302);
            }
            Flash::set('success', 'Tax rate created.');

            return $response->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.commerce.tax_rates'))->withStatus(302);
        })->setName('admin.commerce.tax_rates.create');

        $group->get('/tax-rates/{id}/edit', function (Request $request, Response $response, array $args) use ($twig, $adminContext, $withCmsUser, $taxRates): Response {
            $rate = $taxRates->findById((int) ($args['id'] ?? 0));
            if ($rate === null) {
                throw new HttpNotFoundException($request);
            }

            return $twig->render($response, 'admin/commerce/tax_rate_form.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'commerce_tax',
                'tax_rate' => $rate,
            ])));
        })->setName('admin.commerce.tax_rates.edit');

        $group->post('/tax-rates/{id}', function (Request $request, Response $response, array $args) use ($taxRates): Response {
            $id = (int) ($args['id'] ?? 0);
            $rate = $taxRates->findById($id);
            $back = RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.commerce.tax_rates.edit', ['id' => $id]);
            if ($rate === null) {
                throw new HttpNotFoundException($request);
            }
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $taxRates->update($id, [
                'label' => isset($body['label']) && is_string($body['label']) ? trim($body['label']) : $rate->label,
                'rate_bps' => isset($body['rate_bps']) ? (int) $body['rate_bps'] : $rate->rateBps,
                'sort_order' => isset($body['sort_order']) ? (int) $body['sort_order'] : $rate->sortOrder,
                'is_active' => !empty($body['is_active']),
            ]);
            Flash::set('success', 'Tax rate updated.');

            return $response->withHeader('Location', $back)->withStatus(302);
        })->setName('admin.commerce.tax_rates.update');

        $group->post('/tax-rates/{id}/delete', function (Request $request, Response $response, array $args) use ($taxRates): Response {
            $taxRates->delete((int) ($args['id'] ?? 0));
            Flash::set('success', 'Tax rate deleted.');

            return $response->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.commerce.tax_rates'))->withStatus(302);
        })->setName('admin.commerce.tax_rates.delete');

        $group->get('/inventory', function (Request $request, Response $response) use ($twig, $adminContext, $withCmsUser, $lowStock, $commerce): Response {
            return $twig->render($response, 'admin/commerce/inventory.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'commerce_inventory',
                'low_stock_items' => $lowStock->items(),
                'low_stock_threshold' => $commerce->lowStockThreshold(),
                'inventory_tracking' => $commerce->trackInventory(),
            ])));
        })->setName('admin.commerce.inventory');
    })->add($perm)->add($middleware);
};
