<?php

declare(strict_types=1);

namespace ContentStreamPlugin;

use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Generate and persist a monthly keyword plan from domain analysis (wizard step 3).
 * Returns JSON by default; with body.response_format === "html" and Twig, returns an HTML fragment for the admin UI.
 */
final class KeywordPlanGenerateHandler
{
    /**
     * @param array<string, mixed>|null $viewData merged into Twig when rendering HTML
     */
    public static function handle(
        Request $request,
        Response $response,
        PDO $pdo,
        ?Twig $twig = null,
        ?array $viewData = null,
    ): Response {
        try {
            $parsed = $request->getParsedBody();
            if (!is_array($parsed)) {
                $err = ['error' => 'Expected JSON body.', 'plan' => null, 'items' => []];

                return self::respond($request, $response, $twig, $viewData, [], $err);
            }

            $result = self::execute($pdo, $parsed);

            return self::respond($request, $response, $twig, $viewData, $parsed, $result);
        } catch (\Throwable $e) {
            $err = [
                'error' => $e->getMessage(),
                'plan' => null,
                'items' => [],
            ];

            return self::respond($request, $response, $twig, $viewData, [], $err);
        }
    }

    /**
     * @param array<string, mixed> $parsed
     * @param array{error: string, plan: array<string, mixed>|null, items: list<array<string, mixed>>} $result
     */
    private static function respond(
        Request $request,
        Response $response,
        ?Twig $twig,
        ?array $viewData,
        array $parsed,
        array $result,
    ): Response {
        $wantHtml = (($parsed['response_format'] ?? '') === 'html')
            && $twig !== null
            && str_contains($request->getHeaderLine('Accept'), 'text/html');

        if ($wantHtml) {
            return self::htmlFragment($response, $twig, $viewData ?? [], $result);
        }

        return self::json($response, $result);
    }

    /**
     * @param array<string, mixed> $globals
     * @param array{error: string, plan: array<string, mixed>|null, items: list<array<string, mixed>>} $result
     */
    private static function htmlFragment(Response $response, Twig $twig, array $globals, array $result): Response
    {
        $err = trim((string) ($result['error'] ?? ''));
        $plan = $result['plan'];
        $items = $result['items'] ?? [];

        $html = $twig->fetch('@plugin_content_stream_plugin/admin/partials/keyword_plan_result_html.twig', array_merge($globals, [
            'cs_plan_html_plan' => is_array($plan) ? $plan : [],
            'cs_plan_html_items' => is_array($items) ? $items : [],
            'cs_plan_html_error' => $err !== '' ? $err : null,
        ]));

        $out = $response
            ->withStatus(200)
            ->withHeader('Content-Type', 'text/html; charset=utf-8');
        $out->getBody()->write($html);

        return $out;
    }

    /**
     * @param array<string, mixed> $body keys: year_month, label?, analysis (array)
     *
     * @return array{error: string, plan: array<string, mixed>|null, items: list<array<string, mixed>>}
     */
    public static function execute(PDO $pdo, array $body): array
    {
        try {
            $kr = new KeywordPlanRepository($pdo);
            if (!$kr->plansTableExists()) {
                return ['error' => 'Keyword Engine tables are missing. Run migration 003 or re-activate the plugin.', 'plan' => null, 'items' => []];
            }

            $settingsRepo = new SettingsRepository($pdo);
            $settings = $settingsRepo->get();
            if (!$settings['api_key_stored']) {
                return ['error' => 'Configure an OpenAI API key in Content Stream settings first.', 'plan' => null, 'items' => []];
            }

            $ym = trim((string) ($body['year_month'] ?? ''));
            if (!preg_match('/^\d{4}-\d{2}$/', $ym)) {
                return ['error' => 'Choose a valid month.', 'plan' => null, 'items' => []];
            }
            $dt = \DateTimeImmutable::createFromFormat('!Y-m', $ym);
            if ($dt === false) {
                return ['error' => 'Invalid month.', 'plan' => null, 'items' => []];
            }
            $days = (int) $dt->format('t');

            $analysis = $body['analysis'] ?? null;
            if (!is_array($analysis)) {
                return ['error' => 'Missing or invalid "analysis" object.', 'plan' => null, 'items' => []];
            }

            $label = trim((string) ($body['label'] ?? ''));
            if ($label === '' && isset($analysis['business_name']) && is_string($analysis['business_name'])) {
                $label = trim($analysis['business_name']);
            }

            $domain = null;
            $url = isset($analysis['website_url']) && is_string($analysis['website_url']) ? trim($analysis['website_url']) : '';
            if ($url !== '') {
                $parts = parse_url($url);
                if (is_array($parts) && !empty($parts['host']) && is_string($parts['host'])) {
                    $domain = strtolower($parts['host']);
                }
            }

            $gen = new OpenAiKeywordPlanGenerator();
            $items = $gen->generate(
                $settings['openai_api_key'],
                $settings['openai_organization'],
                $settings['openai_model'],
                $analysis,
                $days
            );
            $planId = $kr->createPlan($ym, $label !== '' ? $label : null, $domain, $analysis, $items);

            $data = $kr->findPlanWithItems($planId);
            $plan = $data['plan'];
            if ($plan === null) {
                return ['error' => 'Plan was created but could not be loaded.', 'plan' => null, 'items' => []];
            }

            unset($plan['analysis_json']);

            $itemsOut = [];
            foreach ($data['items'] as $row) {
                $lines = json_decode((string) ($row['outline_json'] ?? ''), true);
                $row['outline_lines'] = is_array($lines) ? $lines : [];
                unset($row['outline_json']);
                $itemsOut[] = $row;
            }

            return ['error' => '', 'plan' => $plan, 'items' => $itemsOut];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage(), 'plan' => null, 'items' => []];
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function json(Response $response, array $payload): Response
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR;
        $json = json_encode($payload, $flags);
        if ($json === false) {
            $json = json_encode([
                'error' => 'Could not encode response JSON.',
                'plan' => null,
                'items' => [],
            ], $flags) ?: '{"error":"Could not encode response JSON.","plan":null,"items":[]}';
        }
        $out = $response
            ->withStatus(200)
            ->withHeader('Content-Type', 'application/json; charset=utf-8');
        $out->getBody()->write($json);

        return $out;
    }
}
