<?php

declare(strict_types=1);

use App\Access\PermissionSlug;
use App\Analytics\ExternalLinkClickRepository;
use App\Analytics\ExternalLinkTrackingConfig;
use App\Flash;
use App\Http\Middleware\RequireCmsStaff;
use App\Http\Middleware\RequirePermission;
use PHPAuth\Auth;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Routing\RouteContext;
use Slim\Views\Twig;

/**
 * @param callable(): array<string, mixed> $viewData
 */
return static function (App $app, Twig $twig, Auth $auth, \PDO $pdo, callable $viewData): void {
    $middleware = new RequireCmsStaff($auth, $pdo);
    $perm = new RequirePermission($pdo, [PermissionSlug::VIEW_LINK_ANALYTICS]);

    $adminContext = static fn (): array => array_merge($viewData(), []);
    $withCmsUser = static function (Request $request, array $data): array {
        /** @var array<string, mixed> $cmsUser */
        $cmsUser = $request->getAttribute('cms_user') ?? [];

        return array_merge($data, ['cms_user' => $cmsUser]);
    };

    $rangeMap = [
        '7' => 7,
        '30' => 30,
        '90' => 90,
        'all' => 0,
    ];

    $resolveRange = static function (?string $raw) use ($rangeMap): array {
        $key = is_string($raw) && isset($rangeMap[$raw]) ? $raw : '30';
        $days = $rangeMap[$key];
        $since = $days > 0 ? (time() - ($days * 86400)) : null;

        return ['key' => $key, 'days' => $days, 'since' => $since];
    };

    $app->get('/admin/analytics/external-links', function (
        Request $request,
        Response $response
    ) use ($twig, $pdo, $adminContext, $withCmsUser, $resolveRange): Response {
        $repo = new ExternalLinkClickRepository($pdo);
        $rangeRaw = $request->getQueryParams()['range'] ?? '30';
        $range = $resolveRange(is_string($rangeRaw) ? $rangeRaw : '30');

        $top = $repo->topDestinations(25, $range['since']);
        $sources = $repo->topSourcePages(25, $range['since']);
        $recent = $repo->recent(50);
        $total = $repo->totalClicksSince($range['since']);

        return $twig->render($response, 'admin/analytics/external_links.twig', $withCmsUser($request, array_merge($adminContext(), [
            'admin_nav' => 'analytics_external_links',
            'range' => $range['key'],
            'top_destinations' => $top,
            'top_sources' => $sources,
            'recent_clicks' => $recent,
            'total_clicks' => $total,
            'tracking_enabled' => ExternalLinkTrackingConfig::enabled(),
            'exclude_hosts' => implode("\n", ExternalLinkTrackingConfig::excludedHosts()),
            'retention_days' => ExternalLinkTrackingConfig::retentionDays(),
        ])));
    })->setName('admin.analytics.external_links')->add($perm)->add($middleware);

    $app->post('/admin/analytics/external-links/settings', function (
        Request $request,
        Response $response
    ) use ($pdo): Response {
        $body = (array) $request->getParsedBody();
        $enabled = !empty($body['enabled']);
        $excludeHosts = isset($body['exclude_hosts']) && is_string($body['exclude_hosts']) ? $body['exclude_hosts'] : '';
        $retentionDays = isset($body['retention_days']) && is_string($body['retention_days']) ? (int) $body['retention_days'] : 0;
        ExternalLinkTrackingConfig::save($pdo, $enabled, $excludeHosts, $retentionDays);
        Flash::set('success', 'External-link tracking settings saved.');

        $url = RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.analytics.external_links');

        return $response->withHeader('Location', $url)->withStatus(302);
    })->setName('admin.analytics.external_links.settings')->add($perm)->add($middleware);

    $app->post('/admin/analytics/external-links/purge', function (
        Request $request,
        Response $response
    ) use ($pdo): Response {
        $repo = new ExternalLinkClickRepository($pdo);
        $body = (array) $request->getParsedBody();
        $mode = is_string($body['mode'] ?? null) ? $body['mode'] : 'all';
        if ($mode === 'older_than') {
            $days = isset($body['days']) ? (int) $body['days'] : 30;
            $deleted = $repo->purgeOlderThan(max(1, $days));
            Flash::set('success', 'Removed ' . $deleted . ' click(s) older than ' . max(1, $days) . ' day(s).');
        } else {
            $pdo->exec('TRUNCATE TABLE cms_external_link_clicks');
            Flash::set('success', 'External-link click log cleared.');
        }
        $url = RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.analytics.external_links');

        return $response->withHeader('Location', $url)->withStatus(302);
    })->setName('admin.analytics.external_links.purge')->add($perm)->add($middleware);
};
