<?php

declare(strict_types=1);

use App\Plugin\PluginBootContext;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Routing\RouteContext;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use StripeStorePlugin\CheckoutService;
use StripeStorePlugin\ProductCheckoutResolver;
use StripeStorePlugin\SettingsRepository;
use StripeStorePlugin\StripeBootstrap;
use StripeStorePlugin\StripeStoreCsrf;

return function (App $app, PluginBootContext $ctx): void {
    $pluginRoot = $ctx->pluginRoot();
    $pdo = $ctx->pdo();
    $twig = $ctx->twig();

    $app->group('/stripe-store', function (\Slim\Routing\RouteCollectorProxy $group) use ($ctx, $twig, $pluginRoot, $pdo): void {
        $group->get('/config.json', function (Request $request, Response $response) use ($pdo): Response {
            $settings = new SettingsRepository($pdo);
            if (!$settings->tableExists()) {
                $response->getBody()->write(json_encode(['ok' => false, 'embed_enabled' => false]));

                return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
            }
            $s = $settings->get();
            $response->getBody()->write(json_encode([
                'ok' => true,
                'embed_enabled' => $s['embed_enabled'],
                'allowed_type_slugs' => $settings->allowedTypeSlugList(),
                'publishable_key' => $s['publishable_key'],
                'button_label' => $s['button_label'],
            ], JSON_THROW_ON_ERROR));

            return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
        });

        $group->get('/csrf.json', function (Request $request, Response $response): Response {
            $token = StripeStoreCsrf::token();
            $response->getBody()->write(json_encode(['csrf_token' => $token], JSON_THROW_ON_ERROR));

            return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
        });

        $group->get('/embed.js', function (Request $request, Response $response) use ($pdo, $ctx): Response {
            $settings = new SettingsRepository($pdo);
            if (!$settings->tableExists() || !$settings->get()['embed_enabled']) {
                $response->getBody()->write('/* Stripe store: embed disabled or database table missing (run plugin migration). */');

                return $response->withHeader('Content-Type', 'application/javascript; charset=utf-8');
            }

            $base = rtrim((string) ($ctx->viewData()['site_url'] ?? ''), '/');
            $allowedJson = json_encode($settings->allowedTypeSlugList(), JSON_THROW_ON_ERROR);
            $labelJson = json_encode($settings->get()['button_label'], JSON_THROW_ON_ERROR);
            $baseJson = json_encode($base, JSON_THROW_ON_ERROR);

            $js = <<<'JS'
(function () {
  var BASE = BASE_PLACEHOLDER;
  var ALLOWED = ALLOWED_PLACEHOLDER;
  var BTN = BTN_PLACEHOLDER;

  function matchEntryPath() {
    var p = (location.pathname || "/").replace(/\/+$/, "");
    var m = p.match(/^\/([^/]+)\/([^/]+)$/);
    if (!m) return null;
    var typeSlug = m[1].toLowerCase();
    if (ALLOWED.indexOf(typeSlug) === -1) return null;
    return { typeSlug: typeSlug, entrySlug: m[2] };
  }

  function mount() {
    var hit = matchEntryPath();
    if (!hit) return;
    var anchor =
      document.querySelector(".product-detail-actions") ||
      document.querySelector(".product-detail-intro") ||
      document.querySelector("article.product-detail");
    if (!anchor) return;

    fetch(BASE + "/stripe-store/config.json", { credentials: "same-origin" })
      .then(function (r) { return r.json(); })
      .then(function (cfg) {
        if (!cfg || !cfg.embed_enabled) return;
        return fetch(BASE + "/stripe-store/csrf.json", { credentials: "same-origin" }).then(function (r) {
          return r.json();
        });
      })
      .then(function (c) {
        if (!c || !c.csrf_token) return;
        var wrap = document.createElement("div");
        wrap.className = "stripe-store-buy-wrap";
        wrap.setAttribute("data-stripe-store", "1");
        wrap.style.cssText = "margin:0.75rem 0 0;width:100%;max-width:28rem;";
        var form = document.createElement("form");
        form.method = "post";
        form.action = BASE + "/stripe-store/checkout";
        [["csrf_token", c.csrf_token], ["type_slug", hit.typeSlug], ["entry_slug", hit.entrySlug]].forEach(function (pair) {
          var i = document.createElement("input");
          i.type = "hidden";
          i.name = pair[0];
          i.value = pair[1];
          form.appendChild(i);
        });
        var btn = document.createElement("button");
        btn.type = "submit";
        btn.className = "btn btn-primary";
        btn.textContent = BTN;
        form.appendChild(btn);
        wrap.appendChild(form);
        if (anchor.classList && anchor.classList.contains("product-detail-actions")) {
          anchor.parentNode.insertBefore(wrap, anchor);
        } else {
          anchor.appendChild(wrap);
        }
      })
      .catch(function () {});
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", mount);
  } else {
    mount();
  }
})();
JS;
            $js = str_replace(
                ['BASE_PLACEHOLDER', 'ALLOWED_PLACEHOLDER', 'BTN_PLACEHOLDER'],
                [$baseJson, $allowedJson, $labelJson],
                $js
            );
            $response->getBody()->write($js);

            return $response->withHeader('Content-Type', 'application/javascript; charset=utf-8');
        });

        $group->post('/checkout', function (Request $request, Response $response) use ($pdo, $ctx, $pluginRoot, $twig): Response {
            try {
                StripeBootstrap::load($pluginRoot);
            } catch (\Throwable $e) {
                return $twig->render(
                    $response->withStatus(500),
                    '@plugin_stripe_store_plugin/public/error.twig',
                    array_merge($ctx->viewData(), ['message' => $e->getMessage()])
                );
            }

            $settings = new SettingsRepository($pdo);
            if (!$settings->tableExists()) {
                $response->getBody()->write('Stripe store is not configured.');

                return $response->withStatus(503)->withHeader('Content-Type', 'text/plain; charset=utf-8');
            }

            $parsed = $request->getParsedBody();
            $csrf = is_array($parsed) ? ($parsed['csrf_token'] ?? null) : null;
            if (!StripeStoreCsrf::validate(is_string($csrf) ? $csrf : null)) {
                return $response->withStatus(403)->withHeader('Content-Type', 'text/plain; charset=utf-8');
            }

            $typeSlug = is_array($parsed) ? trim((string) ($parsed['type_slug'] ?? '')) : '';
            $entrySlug = is_array($parsed) ? trim((string) ($parsed['entry_slug'] ?? '')) : '';
            $allowed = $settings->allowedTypeSlugList();

            $resolver = new ProductCheckoutResolver($pdo);
            $product = $resolver->resolve($typeSlug, $entrySlug, $allowed);
            if ($product === null) {
                return $response->withStatus(404)->withHeader('Content-Type', 'text/plain; charset=utf-8');
            }

            $secret = $settings->effectiveSecretKey();
            if ($secret === '') {
                return $twig->render(
                    $response->withStatus(503),
                    '@plugin_stripe_store_plugin/public/error.twig',
                    array_merge($ctx->viewData(), ['message' => 'Stripe secret key is not set (admin or STRIPE_SECRET_KEY).'])
                );
            }

            $siteUrl = rtrim((string) ($ctx->viewData()['site_url'] ?? ''), '/');
            $parser = RouteContext::fromRequest($request)->getRouteParser();
            $successUrl = $siteUrl . $parser->urlFor('plugin.stripe_store_plugin.success') . '?session_id={CHECKOUT_SESSION_ID}';
            $cancelUrl = $siteUrl . $parser->urlFor('plugin.stripe_store_plugin.cancel');

            try {
                $checkout = new CheckoutService($secret, $settings->get()['currency']);
                $session = $checkout->createSession($product, $successUrl, $cancelUrl);
            } catch (\Throwable $e) {
                return $twig->render(
                    $response->withStatus(502),
                    '@plugin_stripe_store_plugin/public/error.twig',
                    array_merge($ctx->viewData(), ['message' => 'Checkout could not be started. Check Stripe keys and product price fields.'])
                );
            }

            return $response
                ->withStatus(303)
                ->withHeader('Location', $session->url ?? '/');
        });

        $group->get('/success', function (Request $request, Response $response) use ($twig, $ctx): Response {
            $sessionId = $request->getQueryParams()['session_id'] ?? '';

            return $twig->render($response, '@plugin_stripe_store_plugin/public/success.twig', array_merge($ctx->viewData(), [
                'stripe_session_id' => is_string($sessionId) ? $sessionId : '',
            ]));
        })->setName('plugin.stripe_store_plugin.success');

        $group->get('/cancel', function (Request $request, Response $response) use ($twig, $ctx): Response {
            return $twig->render($response, '@plugin_stripe_store_plugin/public/cancel.twig', $ctx->viewData());
        })->setName('plugin.stripe_store_plugin.cancel');

        $group->post('/webhook', function (Request $request, Response $response) use ($pdo, $pluginRoot): Response {
            try {
                StripeBootstrap::load($pluginRoot);
            } catch (\Throwable) {
                return $response->withStatus(500);
            }

            $settings = new SettingsRepository($pdo);
            $whSecret = $settings->effectiveWebhookSecret();
            if ($whSecret === '') {
                return $response->withStatus(503);
            }

            $payload = file_get_contents('php://input') ?: '';
            $sigHeader = $request->getHeaderLine('Stripe-Signature');

            try {
                Webhook::constructEvent($payload, $sigHeader, $whSecret);
            } catch (SignatureVerificationException|\UnexpectedValueException) {
                return $response->withStatus(400);
            }

            return $response->withStatus(200);
        });
    });
};
