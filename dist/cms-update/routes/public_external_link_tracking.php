<?php

declare(strict_types=1);

use App\Analytics\ExternalLinkClickRepository;
use App\Analytics\ExternalLinkTrackingConfig;
use App\Http\ClientIp;
use App\Security\FileRateLimiter;
use PHPAuth\Auth;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;

return static function (App $app, \PDO $pdo, string $projectRoot, Auth $auth): void {
    $repo = new ExternalLinkClickRepository($pdo);
    $rate = new FileRateLimiter($projectRoot . '/storage/cache/external_link_rate');

    $app->post('/track/external-link', function (Request $request, Response $response) use ($repo, $rate, $auth): Response {
        $noContent = static fn (): Response => $response->withStatus(204)
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Referrer-Policy', 'same-origin');

        if (!ExternalLinkTrackingConfig::enabled()) {
            return $noContent();
        }

        $ip = ClientIp::fromRequest($request);
        // Modest rate limit: 60 clicks / minute / IP. Genuine users won't hit it; scrapers will.
        if (!$rate->hit('external_link', $ip, 60, 60)) {
            return $response->withStatus(429)->withHeader('Cache-Control', 'no-store');
        }

        $raw = (string) $request->getBody();
        if ($raw === '' || strlen($raw) > 8192) {
            return $noContent();
        }
        try {
            /** @var mixed $payload */
            $payload = json_decode($raw, true, 6, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $noContent();
        }
        if (!is_array($payload)) {
            return $noContent();
        }

        $destination = isset($payload['u']) && is_string($payload['u']) ? trim($payload['u']) : '';
        $sourcePath = isset($payload['p']) && is_string($payload['p']) ? trim($payload['p']) : '';
        $sourceUrl = isset($payload['s']) && is_string($payload['s']) ? trim($payload['s']) : '';
        $referrer = isset($payload['r']) && is_string($payload['r']) ? trim($payload['r']) : '';
        $linkText = isset($payload['t']) && is_string($payload['t']) ? trim($payload['t']) : '';

        // Destination must look like a real http(s) URL.
        if ($destination === '' || !preg_match('#^https?://#i', $destination)) {
            return $noContent();
        }
        $destHost = parse_url($destination, PHP_URL_HOST);
        if (!is_string($destHost) || $destHost === '') {
            return $noContent();
        }
        $destHost = strtolower($destHost);

        // Same host as us? Then it's internal — ignore.
        $requestHost = strtolower($request->getUri()->getHost());
        if (self_hosts_match($destHost, $requestHost)) {
            return $noContent();
        }
        if (ExternalLinkTrackingConfig::isHostExcluded($destHost)) {
            return $noContent();
        }

        if ($sourcePath === '' || !str_starts_with($sourcePath, '/')) {
            $sourcePath = '/';
        }
        // Don't accept anything that contains control chars / newlines etc.
        $sourcePath = preg_replace('/[\x00-\x1F\x7F]/', '', $sourcePath) ?? '/';

        if ($sourceUrl !== '' && !preg_match('#^https?://#i', $sourceUrl)) {
            $sourceUrl = '';
        }
        // Only store the referrer when it's a different host (i.e. user came from outside).
        $referrerExternal = '';
        if ($referrer !== '' && preg_match('#^https?://#i', $referrer)) {
            $refHost = parse_url($referrer, PHP_URL_HOST);
            if (is_string($refHost) && $refHost !== '' && !self_hosts_match(strtolower($refHost), $requestHost)) {
                $referrerExternal = $referrer;
            }
        }

        $userId = null;
        if ($auth->isLogged()) {
            $uid = (int) $auth->getCurrentUID();
            if ($uid > 0) {
                $userId = $uid;
            }
        }

        try {
            $repo->insert([
                'destination_url' => $destination,
                'destination_host' => $destHost,
                'source_path' => $sourcePath,
                'source_url' => $sourceUrl !== '' ? $sourceUrl : null,
                'referrer_external' => $referrerExternal !== '' ? $referrerExternal : null,
                'link_text' => $linkText !== '' ? $linkText : null,
                'client_ip' => $ip,
                'user_agent' => substr((string) $request->getHeaderLine('User-Agent'), 0, 512),
                'user_id' => $userId,
            ]);
        } catch (\Throwable) {
            // Tracker must never break a page navigation.
        }

        return $noContent();
    })->setName('public.tracking.external_link');
};

/**
 * Same-host comparison shared with {@see App\Seo\ExternalLinkPolicy::hostsMatch()} (private there).
 */
function self_hosts_match(string $a, string $b): bool
{
    $a = strtolower($a);
    $b = strtolower($b);
    if ($a === $b) {
        return true;
    }

    return preg_replace('#^www\.#i', '', $a) === preg_replace('#^www\.#i', '', $b);
}
