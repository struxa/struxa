<?php

declare(strict_types=1);

use App\Http\ClientIp;
use App\Search\ContentSearchService;
use App\Search\SearchSettings;
use App\Security\FileRateLimiter;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Exception\HttpNotFoundException;
use Slim\Views\Twig;

return static function (App $app, Twig $twig, \PDO $pdo, string $projectRoot, callable $viewData): void {
    $rate = new FileRateLimiter($projectRoot . '/storage/cache/search_rate');

    $app->get('/search', function (Request $request, Response $response) use (
        $twig,
        $pdo,
        $rate,
        $viewData
    ): Response {
        if (!SearchSettings::enabled()) {
            throw new HttpNotFoundException($request);
        }

        $ip = ClientIp::fromRequest($request);
        // 30 searches / minute / IP. Genuine users won't hit it; scrapers will.
        if (!$rate->hit('search', $ip, 30, 60)) {
            return $response
                ->withStatus(429)
                ->withHeader('Retry-After', '30')
                ->withHeader('Cache-Control', 'no-store');
        }

        $q = $request->getQueryParams();
        $rawQuery = isset($q['q']) && is_string($q['q']) ? $q['q'] : '';
        $page = isset($q['page']) && is_numeric($q['page']) ? max(1, (int) $q['page']) : 1;

        $sanitized = ContentSearchService::sanitizeQuery($rawQuery);
        $allowedTypeIds = SearchSettings::activeAllowedTypeIds($pdo);
        $perPage = SearchSettings::perPage();
        $includeFields = SearchSettings::includeFieldValues();

        $rawTrimmed = trim($rawQuery);
        $tooShort = $rawTrimmed !== '' && $sanitized === '';

        if ($sanitized === '' || $allowedTypeIds === []) {
            $result = [
                'total' => 0,
                'page' => 1,
                'per_page' => $perPage,
                'total_pages' => 0,
                'hits' => [],
                'query' => $sanitized,
            ];
        } else {
            $service = new ContentSearchService($pdo);
            $result = $service->search($sanitized, $allowedTypeIds, $includeFields, $page, $perPage);
        }

        $response = $response
            ->withHeader('X-Robots-Tag', 'noindex, follow')
            ->withHeader('Cache-Control', 'no-store');

        return $twig->render($response, 'public/search.twig', array_merge($viewData(), [
            'struxa_seo' => true,
            'struxa_html_title' => ($sanitized !== '' ? ($sanitized . ' — ') : '') . 'Search',
            'struxa_meta_description' => '',
            'struxa_canonical_url' => '',
            'search_query_raw' => $rawTrimmed,
            'search_query' => $sanitized,
            'search_too_short' => $tooShort,
            'search_min_length' => ContentSearchService::MIN_QUERY_LENGTH,
            'search_max_length' => ContentSearchService::MAX_QUERY_LENGTH,
            'search_result' => $result,
            'search_enabled' => true,
        ]));
    })->setName('public.search');
};
