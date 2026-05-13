<?php

declare(strict_types=1);

use App\Access\PermissionSlug;
use App\Flash;
use App\Http\Middleware\RequireCmsStaff;
use App\Http\Middleware\RequirePermission;
use App\Plugin\PluginBootContext;
use AviosDestinationReviewPlugin\ContentEntryService;
use AviosDestinationReviewPlugin\DependencyChecker;
use AviosDestinationReviewPlugin\ImageGenerator;
use AviosDestinationReviewPlugin\ReviewGenerator;
use AviosDestinationReviewPlugin\ReviewRepository;
use AviosDestinationReviewPlugin\SettingsRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Routing\RouteContext;

return function (App $app, PluginBootContext $ctx): void {
    $authMw = new RequireCmsStaff($ctx->auth(), $ctx->pdo());
    $contentMw = new RequirePermission($ctx->pdo(), [PermissionSlug::EDIT_CONTENT]);
    $twig = $ctx->twig();
    $pdo = $ctx->pdo();

    /** @var \Closure(Response, int, array<string,mixed>): Response $jsonResponse */
    $jsonResponse = static function (Response $response, int $status, array $data): Response {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8');
    };

    $app->group('/admin/avios-destination-review', function (\Slim\Routing\RouteCollectorProxy $g) use ($ctx, $twig, $pdo, $jsonResponse): void {

        // ----- List + datatable ----------------------------------------------
        $g->get('', function (Request $request, Response $response) use ($ctx, $twig, $pdo): Response {
            $depChecker = new DependencyChecker($pdo);
            $dep = $depChecker->check();
            $settingsRepo = new SettingsRepository($pdo);
            $contentEntries = ContentEntryService::fromPdo($pdo);

            // For each destination in hma_fares, attach the linked CMS content entry (if any).
            $entriesByIata = $contentEntries->indexByIata();
            $rows = [];
            $generatedCount = 0;
            foreach ($depChecker->destinations() as $d) {
                $iata = (string) $d['iata'];
                $entry = $entriesByIata[$iata] ?? null;
                if ($entry !== null) {
                    $generatedCount++;
                }
                $rows[] = [
                    'iata' => $iata,
                    'destination' => (string) $d['destination'],
                    'entry' => $entry,
                ];
            }

            $cmsUser = $request->getAttribute('cms_user') ?? [];

            return $twig->render($response, '@plugin_avios_destination_review_plugin/admin/list.twig', $ctx->viewData([
                'cms_user' => is_array($cmsUser) ? $cmsUser : [],
                'admin_nav' => 'extensions_plugins',
                'adr_dependency' => $dep,
                'adr_rows' => $rows,
                'adr_total_destinations' => count($rows),
                'adr_generated_count' => $generatedCount,
                'adr_pending_count' => count($rows) - $generatedCount,
                'adr_settings' => $settingsRepo->get(),
                'adr_type_id' => $contentEntries->typeId(),
                'adr_content_type_ready' => $contentEntries->isReady(),
            ]));
        })->setName('plugin.avios_destination_review.list');

        // ----- Generate (JSON) -----------------------------------------------
        $g->post('/generate', function (Request $request, Response $response) use ($pdo, $jsonResponse): Response {
            $body = $request->getParsedBody();
            $iata = strtoupper(trim((string) (is_array($body) ? ($body['iata'] ?? '') : '')));

            $dep = new DependencyChecker($pdo);
            $depCheck = $dep->check();
            if (!$depCheck['ok']) {
                return $jsonResponse($response, 422, ['ok' => false, 'error' => $depCheck['message']]);
            }
            if ($iata === '' || strlen($iata) !== 3) {
                return $jsonResponse($response, 422, ['ok' => false, 'error' => 'Pick a destination first.']);
            }
            $name = $dep->destinationName($iata);
            if ($name === null) {
                return $jsonResponse($response, 422, ['ok' => false, 'error' => 'Unknown IATA code.']);
            }

            $settings = new SettingsRepository($pdo);
            $generator = new ReviewGenerator(
                $settings,
                new ReviewRepository($pdo),
                ContentEntryService::fromPdo($pdo),
                new ImageGenerator($pdo, $settings),
            );
            $result = $generator->generateAndStore($iata, $name);
            if (!$result['ok']) {
                return $jsonResponse($response, 500, ['ok' => false, 'error' => $result['error'] ?? 'Generation failed.']);
            }

            return $jsonResponse($response, 200, [
                'ok' => true,
                'id' => $result['id'],
                'entry_id' => $result['entry_id'] ?? null,
                'iata' => $iata,
                'destination' => $name,
                'review' => $result['review'],
                'media_id' => $result['media_id'] ?? null,
                'image_warning' => $result['image_warning'] ?? '',
            ]);
        })->setName('plugin.avios_destination_review.generate');

        // ----- Generate image only (JSON) ------------------------------------
        // Generates (or regenerates) the hero image for a single existing review and
        // attaches it as the linked content entry's featured image. Lives separately
        // from /generate so editors can keep text untouched while iterating on art.
        $g->post('/{id:[0-9]+}/image', function (Request $request, Response $response, array $args) use ($pdo, $jsonResponse): Response {
            $linkId = (int) $args['id'];
            $contentEntries = ContentEntryService::fromPdo($pdo);
            $link = $contentEntries->findLink($linkId);
            if ($link === null) {
                return $jsonResponse($response, 404, ['ok' => false, 'error' => 'Review not found.']);
            }

            $settings = new SettingsRepository($pdo);
            $generator = new ReviewGenerator(
                $settings,
                new ReviewRepository($pdo),
                $contentEntries
            );
            $apiKey = $generator->resolveApiKey();
            if ($apiKey === '') {
                return $jsonResponse($response, 422, ['ok' => false, 'error' => 'No OpenAI API key configured. Add one under System → API keys (/admin/system/api-keys), or set OPENAI_API_KEY in the environment.']);
            }

            $img = (new ImageGenerator($pdo, $settings))->generateAndStore($apiKey, $link['destination'], $link['iata']);
            if (!$img['ok']) {
                return $jsonResponse($response, 500, ['ok' => false, 'error' => $img['error']]);
            }

            $contentEntries->setFeaturedImage($link['entry_id'], $img['media_id']);

            return $jsonResponse($response, 200, [
                'ok' => true,
                'id' => $linkId,
                'iata' => $link['iata'],
                'destination' => $link['destination'],
                'entry_id' => $link['entry_id'],
                'media_id' => $img['media_id'],
                'image_path' => $img['path'],
                'model' => $img['model'],
                'size' => $img['size'],
            ]);
        })->setName('plugin.avios_destination_review.image');

        // ----- Delete --------------------------------------------------------
        // Deletes BOTH the plugin's link row AND the underlying CMS content entry.
        $g->post('/{id:[0-9]+}/delete', function (Request $request, Response $response, array $args) use ($pdo): Response {
            ContentEntryService::fromPdo($pdo)->deleteForLink((int) $args['id']);
            Flash::set('success', 'Review deleted.');
            $parser = RouteContext::fromRequest($request)->getRouteParser();

            return $response->withHeader('Location', $parser->urlFor('plugin.avios_destination_review.list'))->withStatus(302);
        });

        // ----- Settings ------------------------------------------------------
        $g->get('/settings', function (Request $request, Response $response) use ($ctx, $twig, $pdo): Response {
            $cmsUser = $request->getAttribute('cms_user') ?? [];

            return $twig->render($response, '@plugin_avios_destination_review_plugin/admin/settings.twig', $ctx->viewData([
                'cms_user' => is_array($cmsUser) ? $cmsUser : [],
                'admin_nav' => 'extensions_plugins',
                'adr_settings' => (new SettingsRepository($pdo))->get(),
            ]));
        })->setName('plugin.avios_destination_review.settings');

        $g->post('/settings', function (Request $request, Response $response) use ($pdo): Response {
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $repo = new SettingsRepository($pdo);

            $repo->save([
                'prompt_template' => isset($body['prompt_template']) ? (string) $body['prompt_template'] : '',
                'image_enabled' => !empty($body['image_enabled']),
                'image_model' => isset($body['image_model']) ? (string) $body['image_model'] : '',
                'image_size' => isset($body['image_size']) ? (string) $body['image_size'] : '',
                'image_prompt_template' => isset($body['image_prompt_template']) ? (string) $body['image_prompt_template'] : '',
            ]);
            Flash::set('success', 'Settings saved.');
            $parser = RouteContext::fromRequest($request)->getRouteParser();

            return $response->withHeader('Location', $parser->urlFor('plugin.avios_destination_review.settings'))->withStatus(302);
        });
    })->add($contentMw)->add($authMw);
};
