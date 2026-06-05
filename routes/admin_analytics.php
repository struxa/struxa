<?php

declare(strict_types=1);

use App\Access\PermissionSlug;
use App\Analytics\ExternalLinkClickRepository;
use App\Analytics\ExternalLinkTrackingConfig;
use App\Analytics\ShortLinkConfig;
use App\Analytics\ShortLinkRepository;
use App\Analytics\ShortLinkService;
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

        $shortRepo = new ShortLinkRepository($pdo);
        $shortService = new ShortLinkService($shortRepo, $pdo);
        $siteUrl = rtrim((string) ($adminContext()['site_url'] ?? ''), '/');
        $shortLinkRows = [];
        foreach ($shortRepo->listRecent(100) as $link) {
            $shortLinkRows[] = [
                'id' => $link->id,
                'code' => $link->code,
                'destination_url' => $link->destinationUrl,
                'label' => $link->label,
                'clicks' => $link->clicks,
                'created_at' => $link->createdAt,
                'short_url' => $shortService->preferredPublicUrl($link->code, $siteUrl),
            ];
        }

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
            'short_link_enabled' => ShortLinkConfig::enabled(),
            'short_link_prefix' => ShortLinkConfig::prefixSegment(),
            'short_link_root_mode' => ShortLinkConfig::rootModeEnabled(),
            'short_link_rows' => $shortLinkRows,
            'short_link_example_url' => $shortService->preferredPublicUrl('abc123', $siteUrl !== '' ? $siteUrl : 'https://example.com'),
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
        $shortEnabled = !empty($body['short_link_enabled']);
        $shortPrefix = isset($body['short_link_prefix']) && is_string($body['short_link_prefix']) ? $body['short_link_prefix'] : 'go';
        $shortRoot = !empty($body['short_link_root_mode']);
        if ($shortEnabled && !$shortRoot && trim($shortPrefix, '/') === '') {
            Flash::set('error', 'Set a URL prefix (e.g. go) or enable root short URLs.');
            $url = RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.analytics.external_links') . '#analytics-settings';

            return $response->withHeader('Location', $url)->withStatus(302);
        }
        ShortLinkConfig::save($pdo, $shortEnabled, $shortPrefix, $shortRoot);
        Flash::set('success', 'External link and short link settings saved.');

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

    $app->post('/admin/analytics/external-links/short-links/create', function (
        Request $request,
        Response $response
    ) use ($pdo): Response {
        $body = (array) $request->getParsedBody();
        $destination = isset($body['destination_url']) && is_string($body['destination_url']) ? trim($body['destination_url']) : '';
        $code = isset($body['code']) && is_string($body['code']) ? trim($body['code']) : '';
        $label = isset($body['label']) && is_string($body['label']) ? trim($body['label']) : '';
        /** @var array<string, mixed> $cmsUser */
        $cmsUser = $request->getAttribute('cms_user') ?? [];
        $createdBy = isset($cmsUser['id']) ? (int) $cmsUser['id'] : null;
        if ($createdBy !== null && $createdBy < 1) {
            $createdBy = null;
        }

        $service = new ShortLinkService(new ShortLinkRepository($pdo), $pdo);
        $result = $service->create($destination, $code !== '' ? $code : null, $label !== '' ? $label : null, $createdBy);
        if (!$result['ok']) {
            Flash::set('error', $result['error']);
        } else {
            Flash::set('success', 'Short link created: ' . $result['code']);
        }

        $url = RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.analytics.external_links') . '#short-links';

        return $response->withHeader('Location', $url)->withStatus(302);
    })->setName('admin.analytics.short_links.create')->add($perm)->add($middleware);

    $app->post('/admin/analytics/external-links/short-links/{id:[0-9]+}/delete', function (
        Request $request,
        Response $response,
        array $args
    ) use ($pdo): Response {
        $id = (int) ($args['id'] ?? 0);
        $repo = new ShortLinkRepository($pdo);
        if ($id < 1 || !$repo->delete($id)) {
            Flash::set('error', 'Short link not found.');
        } else {
            Flash::set('success', 'Short link deleted.');
        }
        $url = RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.analytics.external_links') . '#short-links';

        return $response->withHeader('Location', $url)->withStatus(302);
    })->setName('admin.analytics.short_links.delete')->add($perm)->add($middleware);
};
