<?php

declare(strict_types=1);

use App\Content\ContentEntryRepository;
use App\Page\PageRepository;
use App\Seo\SitemapOptions;
use App\Seo\SitemapService;
use App\Settings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Exception\HttpNotFoundException;

/**
 * @param callable(): array<string, mixed> $viewData
 */
return static function (App $app, \PDO $pdo, callable $viewData): void {
    $app->get('/sitemap.xml', function (Request $request, Response $response) use ($pdo, $viewData): Response {
        if (!SitemapOptions::sitemapPubliclyEnabled()) {
            throw new HttpNotFoundException($request);
        }
        $siteUrl = rtrim((string) (($viewData())['site_url'] ?? ''), '/');
        $xml = (new SitemapService($pdo, new PageRepository($pdo), new ContentEntryRepository($pdo)))->xml($siteUrl);
        $response->getBody()->write($xml);

        return $response
            ->withHeader('Content-Type', 'application/xml; charset=utf-8')
            ->withHeader('Cache-Control', 'public, max-age=300');
    })->setName('public.sitemap');

    $app->get('/robots.txt', function (Request $request, Response $response) use ($viewData): Response {
        $siteUrl = rtrim((string) (($viewData())['site_url'] ?? ''), '/');
        $custom = trim((string) Settings::get('robots_txt_custom', ''));
        $lines = [];
        if ($custom !== '') {
            $lines[] = rtrim($custom, "\r\n");
        } else {
            $lines[] = 'User-agent: *';
            $lines[] = 'Disallow: /admin/';
            $lines[] = 'Disallow: /login';
            $lines[] = 'Disallow: /register';
        }
        if (SitemapOptions::sitemapPubliclyEnabled()) {
            $lines[] = '';
            $lines[] = 'Sitemap: ' . $siteUrl . '/sitemap.xml';
        }
        $body = implode("\n", $lines) . "\n";
        $response->getBody()->write($body);

        return $response
            ->withHeader('Content-Type', 'text/plain; charset=utf-8')
            ->withHeader('Cache-Control', 'public, max-age=600');
    })->setName('public.robots');
};
