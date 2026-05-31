<?php

declare(strict_types=1);

use App\Commerce\Catalog\ShopCatalogPage;
use App\Commerce\Customer\CommerceCustomerLinker;
use App\Commerce\Cart\CartResolver;
use App\Commerce\Cart\CartService;
use App\Commerce\CommerceCountryCodes;
use App\Commerce\CommerceSettings;
use App\Commerce\Coupon\CouponRepository;
use App\Commerce\Coupon\CouponService;
use App\Commerce\Digital\DigitalAccessHandler;
use App\Commerce\Digital\DigitalFulfillmentService;
use App\Commerce\Digital\DigitalGrantRepository;
use App\Commerce\Order\CommerceOrderRepository;
use App\Commerce\Order\OrderFulfillmentService;
use App\Commerce\Payment\StripeCheckoutService;
use App\Commerce\Payment\StripeWebhookHandler;
use App\Commerce\Pricing\OrderTotalsCalculator;
use App\Commerce\Product\ProductCatalogEnricher;
use App\Commerce\Product\ProductResolver;
use App\Commerce\Shipping\ShippingZoneRepository;
use App\Commerce\Shipping\ShippingZoneResolver;
use App\Commerce\Tax\TaxRateRepository;
use App\Commerce\Tax\TaxRateResolver;
use App\Content\PublicContentIndexCardBuilder;
use App\Media\MediaUrlHelper;
use App\Content\ContentEntryRepository;
use App\Content\ContentEntryValueRepository;
use App\Content\ContentFieldRepository;
use App\Content\ContentTypeRepository;
use App\Media\MediaRepository;
use App\Flash;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpNotFoundException;
use Slim\Routing\RouteContext;
use Slim\Views\Twig;

return static function (App $app, Twig $twig, \PDO $pdo, callable $viewData): void {
    $root = dirname(__DIR__);
    $commerce = new CommerceSettings($pdo);
    $orders = new CommerceOrderRepository($pdo);
    $types = new ContentTypeRepository($pdo);
    $entries = new ContentEntryRepository($pdo);
    $fields = new ContentFieldRepository($pdo);
    $values = new ContentEntryValueRepository($pdo);
    $products = new ProductResolver($pdo, $commerce, $fields);
    $mediaUrls = new MediaUrlHelper($pdo);
    $indexCards = new PublicContentIndexCardBuilder($fields, $values, $mediaUrls);
    $catalogEnricher = new ProductCatalogEnricher($products, $entries, $values);
    $shopPage = new ShopCatalogPage($commerce, $types, $entries, $fields, $values, $mediaUrls, $indexCards, $catalogEnricher);
    $customerLinker = new CommerceCustomerLinker($pdo, $orders);
    $cart = new CartService();
    $coupons = new CouponService(new CouponRepository($pdo));
    $shippingZoneRepo = new ShippingZoneRepository($pdo);
    $taxRateRepo = new TaxRateRepository($pdo);
    $shippingZones = new ShippingZoneResolver($commerce, $shippingZoneRepo);
    $taxRates = new TaxRateResolver($commerce, $taxRateRepo);
    $totalsCalculator = new OrderTotalsCalculator($commerce, $taxRates, $shippingZones);
    $cartResolver = new CartResolver($cart, $commerce, $types, $entries, $values, $products, $totalsCalculator, $coupons);
    $checkout = new StripeCheckoutService($commerce, $orders, $taxRates, $shippingZones);
    $digital = DigitalFulfillmentService::factory($pdo, $commerce, $types, $fields, $values, $entries, $orders);
    $digitalAccess = new DigitalAccessHandler(
        new DigitalGrantRepository($pdo),
        $orders,
        new MediaRepository($pdo),
        $mediaUrls,
        $root,
    );
    $fulfillment = new OrderFulfillmentService(
        $pdo,
        $commerce,
        $orders,
        new \App\Commerce\Inventory\InventoryService($pdo, $commerce, $types, $values, $products),
        $coupons,
        $digital,
    );
    $webhooks = new StripeWebhookHandler($commerce, $orders, $fulfillment, $customerLinker);

    $requireCommerce = static function (Request $request) use ($commerce): void {
        if (!$commerce->isEnabled()) {
            throw new HttpNotFoundException($request);
        }
    };

    $checkoutUserId = static function () use ($viewData): ?int {
        $vd = $viewData();
        $uid = isset($vd['phpauth_user_id']) ? (int) $vd['phpauth_user_id'] : 0;

        return $uid > 0 ? $uid : null;
    };

    $app->get('/shop', function (Request $request, Response $response) use ($shopPage, $twig, $viewData): Response {
        return $shopPage->render($request, $response, $twig, $viewData);
    })->setName('public.commerce.shop');

    $app->get('/commerce/cart', function (Request $request, Response $response) use ($twig, $viewData, $cartResolver, $requireCommerce, $cart, $commerce, $shippingZoneRepo, $taxRateRepo): Response {
        $requireCommerce($request);
        $resolved = $cartResolver->resolve();
        if (!$resolved['ok']) {
            Flash::set('error', $resolved['error']);
        }

        $preferredCountries = array_merge(
            $shippingZoneRepo->allCountryCodes(),
            array_map(static fn ($r) => $r->countryCode, $taxRateRepo->listActive()),
        );

        return $twig->render($response, 'commerce/cart.twig', array_merge($viewData(), [
            'cart_lines' => $resolved['ok'] ? $resolved['lines'] : [],
            'cart_subtotal_cents' => $resolved['ok'] ? $resolved['subtotal_cents'] : 0,
            'cart_totals' => $resolved['ok'] ? $resolved['totals'] : null,
            'cart_currency' => $resolved['ok'] ? $resolved['currency'] : $commerce->defaultCurrency(),
            'cart_count' => $cart->count(),
            'cart_error' => $resolved['ok'] ? null : $resolved['error'],
            'cart_coupon_code' => $resolved['ok'] ? $resolved['coupon_code'] : null,
            'cart_coupon_error' => $resolved['ok'] ? $resolved['coupon_error'] : null,
            'cart_ship_country' => $resolved['ok'] ? ($resolved['ship_country'] ?? null) : $cart->shipCountry(),
            'cart_country_choices' => CommerceCountryCodes::forSelect($preferredCountries),
            'cart_needs_country' => $commerce->needsCheckoutCountry(),
        ]));
    })->setName('public.commerce.cart');

    $app->post('/commerce/cart/add', function (Request $request, Response $response) use ($cart, $entries, $types, $values, $products, $requireCommerce): Response {
        $requireCommerce($request);
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $entryId = isset($body['content_entry_id']) ? (int) $body['content_entry_id'] : 0;
        $qty = isset($body['quantity']) ? (int) $body['quantity'] : 1;
        $back = isset($body['return_to']) && is_string($body['return_to']) && str_starts_with($body['return_to'], '/')
            ? $body['return_to']
            : RouteContext::fromRequest($request)->getRouteParser()->urlFor('public.commerce.cart');

        if ($entryId < 1) {
            Flash::set('error', 'Missing product.');

            return $response->withHeader('Location', $back)->withStatus(302);
        }

        $entry = $entries->findById($entryId);
        if ($entry === null) {
            throw new HttpNotFoundException($request);
        }
        $type = $types->findById($entry->contentTypeId);
        if ($type === null) {
            throw new HttpNotFoundException($request);
        }
        $product = $products->resolvePublished($type, $entry, $values->valuesByFieldIdForEntry($entryId));
        if ($product === null) {
            Flash::set('error', 'This item is not available for purchase.');

            return $response->withHeader('Location', $back)->withStatus(302);
        }
        if (!$products->hasStock($product, $qty)) {
            Flash::set('error', 'Not enough stock for this item.');

            return $response->withHeader('Location', $back)->withStatus(302);
        }

        $cart->add($entryId, $qty);
        Flash::set('success', 'Added to cart.');

        return $response->withHeader('Location', $back)->withStatus(302);
    })->setName('public.commerce.cart.add');

    $app->post('/commerce/cart/update', function (Request $request, Response $response) use ($cart, $requireCommerce): Response {
        $requireCommerce($request);
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $entryId = isset($body['content_entry_id']) ? (int) $body['content_entry_id'] : 0;
        $qty = isset($body['quantity']) ? (int) $body['quantity'] : 0;
        if ($entryId > 0) {
            $cart->setQuantity($entryId, $qty);
        }
        Flash::set('success', 'Cart updated.');

        return $response->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('public.commerce.cart'))->withStatus(302);
    })->setName('public.commerce.cart.update');

    $app->post('/commerce/cart/country', function (Request $request, Response $response) use ($cart, $requireCommerce): Response {
        $requireCommerce($request);
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $country = isset($body['ship_country']) && is_string($body['ship_country']) ? trim($body['ship_country']) : '';
        $cart->setShipCountry($country !== '' ? $country : null);
        Flash::set('success', 'Shipping country updated.');

        return $response->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('public.commerce.cart'))->withStatus(302);
    })->setName('public.commerce.cart.country');

    $app->post('/commerce/cart/coupon', function (Request $request, Response $response) use ($cart, $cartResolver, $coupons, $requireCommerce): Response {
        $requireCommerce($request);
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $back = RouteContext::fromRequest($request)->getRouteParser()->urlFor('public.commerce.cart');
        $action = isset($body['action']) && is_string($body['action']) ? $body['action'] : 'apply';

        if ($action === 'remove') {
            $cart->clearCoupon();
            Flash::set('success', 'Coupon removed.');

            return $response->withHeader('Location', $back)->withStatus(302);
        }

        $code = isset($body['coupon_code']) && is_string($body['coupon_code']) ? trim($body['coupon_code']) : '';
        $resolved = $cartResolver->resolve();
        if (!$resolved['ok']) {
            Flash::set('error', $resolved['error']);

            return $response->withHeader('Location', $back)->withStatus(302);
        }
        $validation = $coupons->validateForSubtotal($code, $resolved['subtotal_cents']);
        if (!$validation['ok']) {
            Flash::set('error', $validation['error']);

            return $response->withHeader('Location', $back)->withStatus(302);
        }
        $cart->setCouponCode($code);
        Flash::set('success', 'Coupon applied.');

        return $response->withHeader('Location', $back)->withStatus(302);
    })->setName('public.commerce.cart.coupon');

    $app->post('/commerce/cart/checkout', function (Request $request, Response $response) use ($cartResolver, $checkout, $viewData, $requireCommerce, $checkoutUserId, $commerce): Response {
        $requireCommerce($request);
        $resolved = $cartResolver->resolve();
        if (!$resolved['ok']) {
            throw new HttpBadRequestException($request, $resolved['error']);
        }
        if ($commerce->needsCheckoutCountry() && ($resolved['ship_country'] ?? null) === null) {
            throw new HttpBadRequestException($request, 'Select a shipping country before checkout.');
        }
        $err = $cartResolver->validateForCheckout($resolved['lines']);
        if ($err !== null) {
            throw new HttpBadRequestException($request, $err);
        }

        $vd = $viewData();
        $siteUrl = rtrim((string) ($vd['site_url'] ?? ''), '/');
        $result = $checkout->startCartCheckout($resolved['lines'], $siteUrl, $resolved['totals'], $checkoutUserId());
        if (!$result['ok']) {
            throw new HttpBadRequestException($request, $result['error']);
        }

        return $response->withHeader('Location', $result['redirect_url'])->withStatus(302);
    })->setName('public.commerce.cart.checkout');

    $app->post('/commerce/checkout', function (Request $request, Response $response) use (
        $types,
        $entries,
        $values,
        $products,
        $checkout,
        $totalsCalculator,
        $viewData,
        $requireCommerce,
        $checkoutUserId,
        $commerce,
        $cart
    ): Response {
        $requireCommerce($request);

        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $entryId = isset($body['content_entry_id']) ? (int) $body['content_entry_id'] : 0;
        $quantity = isset($body['quantity']) ? (int) $body['quantity'] : 1;
        $shipCountry = isset($body['ship_country']) && is_string($body['ship_country']) ? trim($body['ship_country']) : $cart->shipCountry();
        if ($shipCountry !== '') {
            $cart->setShipCountry($shipCountry);
        }

        if ($entryId < 1) {
            throw new HttpBadRequestException($request, 'Missing product.');
        }

        $entry = $entries->findById($entryId);
        if ($entry === null) {
            throw new HttpNotFoundException($request);
        }

        $type = $types->findById($entry->contentTypeId);
        if ($type === null) {
            throw new HttpNotFoundException($request);
        }

        $valueMap = $values->valuesByFieldIdForEntry($entry->id);
        $product = $products->resolvePublished($type, $entry, $valueMap);
        if ($product === null) {
            throw new HttpBadRequestException($request, 'This item is not available for purchase.');
        }
        if (!$products->hasStock($product, $quantity)) {
            throw new HttpBadRequestException($request, 'Not enough stock.');
        }
        if ($commerce->needsCheckoutCountry() && $cart->shipCountry() === null) {
            throw new HttpBadRequestException($request, 'Select a shipping country before checkout (use the cart page or the country field below).');
        }

        $lineTotal = $product->stripePriceId !== null ? 0 : $product->priceCents * $quantity;
        $totals = $totalsCalculator->calculate($lineTotal, null, null, $cart->shipCountry());

        $vd = $viewData();
        $siteUrl = rtrim((string) ($vd['site_url'] ?? ''), '/');
        $result = $checkout->startCheckout($product, $siteUrl, $quantity, $totals, $checkoutUserId());
        if (!$result['ok']) {
            throw new HttpBadRequestException($request, $result['error']);
        }

        return $response
            ->withHeader('Location', $result['redirect_url'])
            ->withStatus(302);
    })->setName('public.commerce.checkout');

    $app->get('/commerce/checkout/success', function (Request $request, Response $response) use ($twig, $viewData, $orders, $fulfillment, $customerLinker, $cart, $digital, $requireCommerce): Response {
        $requireCommerce($request);

        $q = $request->getQueryParams();
        $sessionId = isset($q['session_id']) && is_string($q['session_id']) ? trim($q['session_id']) : '';
        $order = $sessionId !== '' ? $orders->findByStripeSessionId($sessionId) : null;
        if ($order !== null && $order->status === 'paid') {
            $customerLinker->linkOrderAfterPayment($order->id, $order->customerEmail, $order->customerUserId);
            $fulfillment->fulfillIfPaid($order->id);
            $order = $orders->findByStripeSessionId($sessionId);
            $cart->clear();
        }
        $digitalGrants = ($order !== null && $order->status === 'paid')
            ? $digital->activeGrantsForOrder($order)
            : [];

        return $twig->render($response, 'commerce/checkout_success.twig', array_merge($viewData(), [
            'commerce_order' => $order,
            'checkout_session_id' => $sessionId,
            'digital_grants' => $digitalGrants,
        ]));
    })->setName('public.commerce.checkout_success');

    $app->get('/commerce/access/{token:[a-f0-9]{64}}', function (Request $request, Response $response, array $args) use ($digitalAccess, $requireCommerce): Response {
        $requireCommerce($request);

        return $digitalAccess->serveByToken($request, $response, (string) ($args['token'] ?? ''));
    })->setName('public.commerce.digital.access');

    $app->get('/commerce/orders/lookup', function (Request $request, Response $response) use ($twig, $viewData, $requireCommerce): Response {
        $requireCommerce($request);

        return $twig->render($response, 'commerce/order_lookup.twig', $viewData());
    })->setName('public.commerce.orders.lookup');

    $app->post('/commerce/orders/lookup', function (Request $request, Response $response) use ($twig, $viewData, $orders, $requireCommerce): Response {
        $requireCommerce($request);
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $orderNumber = isset($body['order_number']) && is_string($body['order_number']) ? trim($body['order_number']) : '';
        $email = isset($body['email']) && is_string($body['email']) ? trim($body['email']) : '';
        $order = $orders->findByOrderNumberAndEmail($orderNumber, $email);

        return $twig->render($response, 'commerce/order_lookup.twig', array_merge($viewData(), [
            'lookup_order_number' => $orderNumber,
            'lookup_email' => $email,
            'lookup_order' => $order,
            'lookup_error' => ($orderNumber !== '' && $email !== '' && $order === null)
                ? 'No order found for that number and email.'
                : null,
        ]));
    })->setName('public.commerce.orders.lookup.submit');

    $app->get('/commerce/orders', function (Request $request, Response $response) use ($twig, $viewData, $orders, $requireCommerce): Response {
        $requireCommerce($request);
        $vd = $viewData();
        $email = isset($vd['user_email']) && is_string($vd['user_email']) ? trim($vd['user_email']) : '';
        $userId = isset($vd['phpauth_user_id']) ? (int) $vd['phpauth_user_id'] : 0;
        $loggedIn = !empty($vd['logged_in']) && ($userId > 0 || $email !== '');

        return $twig->render($response, 'commerce/orders.twig', array_merge($vd, [
            'customer_orders' => $loggedIn ? $orders->listForCustomer($userId, $email) : [],
            'orders_requires_login' => !$loggedIn,
        ]));
    })->setName('public.commerce.orders');

    $app->get('/commerce/orders/{order_number}', function (Request $request, Response $response, array $args) use ($twig, $viewData, $orders, $digital, $requireCommerce): Response {
        $requireCommerce($request);
        $vd = $viewData();
        $email = isset($vd['user_email']) && is_string($vd['user_email']) ? trim($vd['user_email']) : '';
        $userId = isset($vd['phpauth_user_id']) ? (int) $vd['phpauth_user_id'] : 0;
        if (($userId < 1 && $email === '') || empty($vd['logged_in'])) {
            Flash::set('error', 'Sign in to view order details.');

            return $response->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('login'))->withStatus(302);
        }
        $orderNumber = isset($args['order_number']) && is_string($args['order_number']) ? trim($args['order_number']) : '';
        $order = $orders->findByOrderNumberForCustomer($orderNumber, $userId, $email);
        if ($order === null) {
            throw new HttpNotFoundException($request);
        }

        return $twig->render($response, 'commerce/order_show.twig', array_merge($vd, [
            'order' => $order,
            'digital_grants' => $digital->activeGrantsForOrder($order),
        ]));
    })->setName('public.commerce.orders.show');

    $app->get('/commerce/orders/{order_number}/access/{grantId:[0-9]+}', function (Request $request, Response $response, array $args) use ($digitalAccess, $orders, $viewData, $requireCommerce): Response {
        $requireCommerce($request);
        $vd = $viewData();
        $email = isset($vd['user_email']) && is_string($vd['user_email']) ? trim($vd['user_email']) : '';
        $userId = isset($vd['phpauth_user_id']) ? (int) $vd['phpauth_user_id'] : 0;
        if (($userId < 1 && $email === '') || empty($vd['logged_in'])) {
            Flash::set('error', 'Sign in to access downloads.');

            return $response->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('login'))->withStatus(302);
        }
        $orderNumber = isset($args['order_number']) && is_string($args['order_number']) ? trim($args['order_number']) : '';
        $order = $orders->findByOrderNumberForCustomer($orderNumber, $userId, $email);
        if ($order === null) {
            throw new HttpNotFoundException($request);
        }
        $grantId = (int) ($args['grantId'] ?? 0);

        return $digitalAccess->serveByGrantId($request, $response, $order->id, $grantId);
    })->setName('public.commerce.orders.access');

    $app->post('/commerce/stripe/webhook', function (Request $request, Response $response) use ($webhooks): Response {
        $payload = (string) $request->getBody();
        $sig = $request->getHeaderLine('Stripe-Signature');
        $result = $webhooks->handle($payload, $sig !== '' ? $sig : null);

        if (!$result['ok']) {
            $response->getBody()->write($result['error']);

            return $response->withStatus($result['status'])->withHeader('Content-Type', 'text/plain; charset=utf-8');
        }

        $response->getBody()->write('ok');

        return $response->withHeader('Content-Type', 'text/plain; charset=utf-8');
    })->setName('public.commerce.stripe_webhook');
};
