<?php

declare(strict_types=1);

use App\Page\PageRepository;
use App\Page\PublicCmsPageRenderer;
use App\Settings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Exception\HttpNotFoundException;
use Slim\Views\Twig;

return static function (App $app, Twig $twig, \PDO $pdo, callable $viewData): void {
    $repo = new PageRepository($pdo);

    $app->get('/p/{slug}', function (Request $request, Response $response, array $args) use ($twig, $viewData, $pdo, $repo): Response {
        $slug = (string) ($args['slug'] ?? '');
        $page = $repo->findPublishedBySlug($slug);
        if ($page === null) {
            throw new HttpNotFoundException($request);
        }

        $homeIdRaw = Settings::publicHomepagePageIdRaw();
        if ($homeIdRaw !== '' && ctype_digit($homeIdRaw) && (int) $homeIdRaw === $page->id) {
            $siteUrl = rtrim((string) (($viewData())['site_url'] ?? ''), '/');

            return $response
                ->withHeader('Location', $siteUrl . '/')
                ->withStatus(301);
        }

        return PublicCmsPageRenderer::render(
            $twig,
            $response,
            $viewData,
            $pdo,
            $page,
            '/p/' . $page->slug,
            false,
            $request,
        );
    })->setName('public.page');
};
