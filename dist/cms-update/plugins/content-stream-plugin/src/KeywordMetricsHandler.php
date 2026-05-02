<?php

declare(strict_types=1);

namespace ContentStreamPlugin;

use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class KeywordMetricsHandler
{
    /**
     * POST JSON { "analysis": { ... } }. CSRF: X-CSRF-Token or body _csrf_token.
     * Always returns 200 with JSON; check "error" for failures (same pattern as domain tool).
     */
    public static function handle(Request $request, Response $response, PDO $pdo): Response
    {
        $reply = static function (array $payload) use ($response): Response {
            $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $out = $response
                ->withStatus(200)
                ->withHeader('Content-Type', 'application/json; charset=utf-8');
            $out->getBody()->write($json);

            return $out;
        };

        try {
            $parsed = $request->getParsedBody();
            if (!is_array($parsed)) {
                return $reply(['error' => 'Expected JSON body with an "analysis" object.', 'rows' => [], 'keywords' => []]);
            }
            $analysis = $parsed['analysis'] ?? null;
            if (!is_array($analysis)) {
                return $reply(['error' => 'Missing or invalid "analysis" object.', 'rows' => [], 'keywords' => []]);
            }

            $repo = new SettingsRepository($pdo);
            if (!$repo->tableExists()) {
                return $reply(['error' => 'Content Stream database table is missing.', 'rows' => [], 'keywords' => []]);
            }

            $settings = $repo->get();
            if (!$settings['api_key_stored']) {
                return $reply(['error' => 'OpenAI is not configured. Add an API key in Content Stream settings.', 'rows' => [], 'keywords' => []]);
            }
            if (!$settings['dataforseo_configured']) {
                return $reply([
                    'error' => 'DataForSEO is not configured. Add API login and password under Content Stream → API settings, and run migration 002_content_stream_dataforseo.sql if needed.',
                    'rows' => [],
                    'keywords' => [],
                ]);
            }

            $keywords = OpenAiKeywordExtractor::keywordsFromDescription(
                $settings['openai_api_key'],
                $settings['openai_organization'],
                $settings['openai_model'],
                $analysis
            );

            if ($keywords === []) {
                $fallback = $analysis['seed_keywords'] ?? [];
                $keywords = KeywordPhraseNormalizer::normalizeList(is_array($fallback) ? $fallback : []);
            }

            if ($keywords === []) {
                return $reply(['error' => 'No keywords could be derived. Ensure business_summary and seed_keywords are present in the analysis.', 'rows' => [], 'keywords' => []]);
            }

            $keywords = array_slice($keywords, 0, 15);

            $dfs = new DataForSeoKeywordMetricsClient(
                $settings['dataforseo_login'],
                $settings['dataforseo_password'],
                $settings['keyword_location_code'],
                $settings['keyword_language_code'],
            );

            $rows = $dfs->buildRows($keywords);

            return $reply([
                'error' => '',
                'keywords' => $keywords,
                'rows' => $rows,
            ]);
        } catch (\Throwable $e) {
            return $reply([
                'error' => $e->getMessage(),
                'rows' => [],
                'keywords' => [],
            ]);
        }
    }
}
