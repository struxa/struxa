<?php

declare(strict_types=1);

use App\Access\PermissionSlug;
use App\Commerce\CommerceSettings;
use App\Commerce\Coupon\CouponRepository;
use App\Commerce\Coupon\CouponService;
use App\Commerce\Inventory\InventoryService;
use App\Commerce\Order\CommerceOrderCsvExporter;
use App\Commerce\Order\CommerceOrderRepository;
use App\Commerce\Order\OrderFulfillmentService;
use App\Commerce\Payment\StripeRefundService;
use App\Commerce\Product\ProductResolver;
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
    $fields = new ContentFieldRepository($pdo);
    $values = new ContentEntryValueRepository($pdo);
    $products = new ProductResolver($pdo, $commerce, $fields);
    $inventory = new InventoryService($pdo, $commerce, $types, $values, $products);
    $couponRepo = new CouponRepository($pdo);
    $coupons = new CouponService($couponRepo);
    $fulfillment = new OrderFulfillmentService($pdo, $commerce, $orders, $inventory, $coupons);
    $refunds = new StripeRefundService($commerce, $orders);

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
        $couponRepo
    ): void {
        $group->get('/orders', function (Request $request, Response $response) use ($twig, $adminContext, $withCmsUser, $orders): Response {
            return $twig->render($response, 'admin/commerce/orders.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'commerce_orders',
                'orders' => $orders->listRecent(200),
            ])));
        })->setName('admin.commerce.orders');

        $group->get('/orders/export.csv', function (Request $request, Response $response) use ($orders): Response {
            $csv = (new CommerceOrderCsvExporter())->export($orders->listRecent(5000));
            $filename = 'commerce-orders-' . gmdate('Y-m-d') . '.csv';
            $response->getBody()->write($csv);

            return $response
                ->withHeader('Content-Type', 'text/csv; charset=utf-8')
                ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');
        })->setName('admin.commerce.orders.export');

        $group->get('/orders/{id}', function (Request $request, Response $response, array $args) use ($twig, $adminContext, $withCmsUser, $orders): Response {
            $id = (int) ($args['id'] ?? 0);
            $order = $orders->findById($id);
            if ($order === null) {
                throw new HttpNotFoundException($request);
            }

            return $twig->render($response, 'admin/commerce/order_show.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'commerce_orders',
                'order' => $order,
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
                'commerce_tax_rate_bps' => $commerce->taxRateBps(),
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

            $settingsRepo->upsert(CommerceSettings::SETTING_ENABLED, $enabled, false);
            $settingsRepo->upsert(CommerceSettings::SETTING_PRODUCT_TYPE_SLUG, $typeSlug, false);
            $settingsRepo->upsert(CommerceSettings::SETTING_CURRENCY, $currency, false);
            $settingsRepo->upsert(CommerceSettings::SETTING_NOTIFY_EMAIL, isset($body['commerce_notify_email']) && is_string($body['commerce_notify_email']) ? trim($body['commerce_notify_email']) : '', false);
            $settingsRepo->upsert(CommerceSettings::SETTING_SEND_ORDER_EMAILS, !empty($body['commerce_send_order_emails']) ? '1' : '0', false);
            $settingsRepo->upsert(CommerceSettings::SETTING_TRACK_INVENTORY, !empty($body['commerce_track_inventory']) ? '1' : '0', false);
            $settingsRepo->upsert(CommerceSettings::SETTING_TAX_ENABLED, !empty($body['commerce_tax_enabled']) ? '1' : '0', false);
            $taxBps = isset($body['commerce_tax_rate_bps']) ? (int) $body['commerce_tax_rate_bps'] : 0;
            $settingsRepo->upsert(CommerceSettings::SETTING_TAX_RATE_BPS, (string) max(0, min(10000, $taxBps)), false);
            $settingsRepo->upsert(CommerceSettings::SETTING_SHIPPING_ENABLED, !empty($body['commerce_shipping_enabled']) ? '1' : '0', false);
            $shipFlat = isset($body['commerce_shipping_flat_cents']) ? max(0, (int) $body['commerce_shipping_flat_cents']) : 0;
            $settingsRepo->upsert(CommerceSettings::SETTING_SHIPPING_FLAT_CENTS, (string) $shipFlat, false);
            $freeMin = isset($body['commerce_free_shipping_min_cents']) ? max(0, (int) $body['commerce_free_shipping_min_cents']) : 0;
            $settingsRepo->upsert(CommerceSettings::SETTING_FREE_SHIPPING_MIN_CENTS, (string) $freeMin, false);
            $shipLabel = isset($body['commerce_shipping_label']) && is_string($body['commerce_shipping_label'])
                ? trim($body['commerce_shipping_label']) : 'Standard shipping';
            $settingsRepo->upsert(CommerceSettings::SETTING_SHIPPING_LABEL, $shipLabel !== '' ? $shipLabel : 'Standard shipping', false);
            $shipCountries = isset($body['commerce_shipping_countries']) && is_string($body['commerce_shipping_countries'])
                ? strtoupper(preg_replace('/[^A-Za-z,\s]/', '', $body['commerce_shipping_countries']) ?? '')
                : 'GB';
            $settingsRepo->upsert(CommerceSettings::SETTING_SHIPPING_COUNTRIES, $shipCountries !== '' ? $shipCountries : 'GB', false);
            $shopTitle = isset($body['commerce_shop_title']) && is_string($body['commerce_shop_title'])
                ? trim($body['commerce_shop_title']) : 'Shop';
            $settingsRepo->upsert(CommerceSettings::SETTING_SHOP_TITLE, $shopTitle !== '' ? $shopTitle : 'Shop', false);
            $shopDesc = isset($body['commerce_shop_description']) && is_string($body['commerce_shop_description'])
                ? trim($body['commerce_shop_description']) : '';
            $settingsRepo->upsert(CommerceSettings::SETTING_SHOP_DESCRIPTION, $shopDesc, false);
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
            Flash::set('success', 'Order refunded via Stripe.');

            return $response->withHeader('Location', $back)->withStatus(302);
        })->setName('admin.commerce.orders.refund');

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
    })->add($perm)->add($middleware);
};
