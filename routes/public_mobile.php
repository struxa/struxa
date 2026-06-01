<?php

declare(strict_types=1);

use App\Mobile\MobileQrCode;
use App\Mobile\MobileSettings;
use App\Mobile\MobileSiteLink;
use App\Settings\SiteUrlResolver;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Exception\HttpNotFoundException;
use Slim\Views\Twig;

/**
 * Public landing page + QR target for “Add this site to Struxa app”.
 */
return static function (App $app, Twig $twig, callable $viewData): void {
    $app->get('/mobile/add', function (Request $request, Response $response) use ($twig, $viewData): Response {
        if (!MobileSettings::enabled()) {
            throw new HttpNotFoundException($request);
        }

        $siteUrl = SiteUrlResolver::resolve();
        $deepLink = MobileSiteLink::deepLinkAddSite($siteUrl);

        return $twig->render($response, 'mobile/add_site.twig', array_merge($viewData(), [
            'mobile_site_url' => $siteUrl,
            'mobile_deeplink' => $deepLink,
        ]));
    })->setName('public.mobile.add_site');

    $app->get('/mobile/add/qr.svg', function (Request $request, Response $response): Response {
        if (!MobileSettings::enabled()) {
            throw new HttpNotFoundException($request);
        }

        $siteUrl = SiteUrlResolver::resolve();
        $deepLink = MobileSiteLink::deepLinkAddSite($siteUrl);
        $svg = MobileQrCode::svg($deepLink, 280);

        $response->getBody()->write($svg);

        return $response
            ->withHeader('Content-Type', 'image/svg+xml; charset=utf-8')
            ->withHeader('Cache-Control', 'public, max-age=300');
    })->setName('public.mobile.add_site_qr');
};
