<?php

declare(strict_types=1);

use App\Access\PermissionSlug;
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
use Slim\Routing\RouteContext;
use Slim\Views\Twig;

/**
 * @param callable(): array<string, mixed> $viewData
 */
return static function (App $app, Twig $twig, Auth $auth, \PDO $pdo, callable $viewData): void {
    $middleware = new RequireCmsStaff($auth, $pdo);
    $perm = new RequirePermission($pdo, [PermissionSlug::MANAGE_SETTINGS]);
    $settingsRepo = new SettingsRepository($pdo);
    $repo = new SystemApiKeyRepository($pdo);

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

    $app->group('/admin', function (\Slim\Routing\RouteCollectorProxy $group) use ($twig, $adminContext, $withCmsUser, $settingsRepo, $repo, $mask, $pdo): void {
        $group->get('/system/api-keys', function (Request $request, Response $response) use ($twig, $adminContext, $withCmsUser, $settingsRepo, $repo, $mask): Response {
            $all = $settingsRepo->allKeyValues();
            $openAi = trim((string) ($all['openai_api_key'] ?? ''));
            $custom = [];
            foreach ($repo->listByProvider('custom') as $row) {
                $custom[] = [
                    'id' => (int) $row['id'],
                    'key_name' => (string) $row['key_name'],
                    'masked' => $mask((string) $row['key_value']),
                    'updated_at' => (string) $row['updated_at'],
                ];
            }

            return $twig->render($response, 'admin/system/api_keys.twig', $withCmsUser($request, array_merge($adminContext(), [
                'admin_nav' => 'system_api_keys',
                'openai_key_stored' => $openAi !== '',
                'openai_key_masked' => $openAi !== '' ? $mask($openAi) : '',
                'openai_env_key' => \App\Ai\OpenAiApiKeyResolver::hasEnvApiKey(),
                'custom_keys' => $custom,
            ])));
        })->setName('admin.system.api_keys');

        $group->post('/system/api-keys/openai', function (Request $request, Response $response) use ($settingsRepo, $pdo): Response {
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $key = isset($body['openai_api_key']) && is_string($body['openai_api_key']) ? trim($body['openai_api_key']) : '';
            if (!empty($body['openai_api_key_clear'])) {
                $settingsRepo->upsert('openai_api_key', '', true);
                Settings::reload($pdo);
                Flash::set('success', 'OpenAI API key removed.');

                return $response->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.system.api_keys'))->withStatus(302);
            }
            if ($key === '' || strlen($key) > 512) {
                Flash::set('error', 'Enter a valid OpenAI key (max 512 chars), or use clear.');

                return $response->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.system.api_keys'))->withStatus(302);
            }
            $settingsRepo->upsert('openai_api_key', $key, true);
            Settings::reload($pdo);
            Flash::set('success', 'OpenAI API key saved.');

            return $response->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.system.api_keys'))->withStatus(302);
        })->setName('admin.system.api_keys.openai');

        $group->post('/system/api-keys/custom', function (Request $request, Response $response) use ($repo): Response {
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $name = isset($body['key_name']) && is_string($body['key_name']) ? trim($body['key_name']) : '';
            $value = isset($body['key_value']) && is_string($body['key_value']) ? trim($body['key_value']) : '';
            if ($name === '' || $value === '') {
                Flash::set('error', 'Name and key value are required.');

                return $response->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.system.api_keys'))->withStatus(302);
            }
            try {
                $repo->upsert('custom', $name, $value);
                Flash::set('success', 'Custom API key saved.');
            } catch (\Throwable $e) {
                Flash::set('error', $e->getMessage());
            }

            return $response->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.system.api_keys'))->withStatus(302);
        })->setName('admin.system.api_keys.custom');

        $group->post('/system/api-keys/{id:[0-9]+}/delete', function (Request $request, Response $response, array $args) use ($repo): Response {
            $id = (int) ($args['id'] ?? 0);
            if ($repo->deleteById($id)) {
                Flash::set('success', 'API key deleted.');
            } else {
                Flash::set('error', 'Could not delete key.');
            }

            return $response->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.system.api_keys'))->withStatus(302);
        })->setName('admin.system.api_keys.delete');

        $group->post('/system/api-keys/{type}/{id:[0-9]+}/reveal', function (Request $request, Response $response, array $args) use ($repo, $settingsRepo): Response {
            $type = (string) ($args['type'] ?? '');
            $id = (int) ($args['id'] ?? 0);
            $value = '';
            if ($type === 'openai' && $id === 1) {
                $all = $settingsRepo->allKeyValues();
                $value = trim((string) ($all['openai_api_key'] ?? ''));
            } elseif ($type === 'custom') {
                $row = $repo->findById($id);
                if (is_array($row) && (string) ($row['provider'] ?? '') === 'custom') {
                    $value = trim((string) ($row['key_value'] ?? ''));
                }
            }
            $payload = ['ok' => $value !== '', 'value' => $value !== '' ? $value : null];
            $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return $response
                ->withStatus($value !== '' ? 200 : 404)
                ->withHeader('Content-Type', 'application/json; charset=utf-8')
                ->withHeader('Cache-Control', 'no-store');
        })->setName('admin.system.api_keys.reveal');
    })->add($perm)->add($middleware);
};
