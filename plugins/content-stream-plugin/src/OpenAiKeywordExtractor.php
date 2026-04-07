<?php

declare(strict_types=1);

namespace ContentStreamPlugin;

/**
 * Uses OpenAI to derive search keywords grounded in business_summary (and related analysis fields).
 */
final class OpenAiKeywordExtractor
{
    private const ENDPOINT = 'https://api.openai.com/v1/chat/completions';

    /**
     * @param array<string, mixed> $analysis Domain analysis JSON (business_summary, seed_keywords, etc.)
     *
     * @return list<string>
     *
     * @throws \RuntimeException
     */
    public static function keywordsFromDescription(
        string $apiKey,
        string $organization,
        string $model,
        array $analysis,
    ): array {
        $model = trim($model) !== '' ? trim($model) : 'gpt-4o-mini';
        $blob = json_encode($analysis, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($blob === false) {
            throw new \RuntimeException('Could not encode analysis for keyword extraction.');
        }
        if (strlen($blob) > 12000) {
            $blob = substr($blob, 0, 12000) . '…';
        }

        $payload = [
            'model' => $model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You pick realistic Google search queries for keyword research. '
                        . 'Prioritize vertical_niche, primary_use_cases, and target_audience from the JSON when present — queries must sound like the customer types and problems actually named there, not a generic "business efficiency" buyer. '
                        . 'Ground every phrase in business_summary and those fields; reuse seed_keywords when accurate. '
                        . 'Avoid a list of interchangeable horizontal SaaS phrases. '
                        . 'Output a single JSON object with key "keywords" only — an array of 10–15 distinct strings. '
                        . 'Each string: max 10 words, max 80 characters, no duplicates, suitable for Google Ads / SEO volume lookup.',
                ],
                [
                    'role' => 'user',
                    'content' => "Business analysis (JSON):\n{$blob}\n\nReturn {\"keywords\":[\"...\"]}.",
                ],
            ],
            'max_tokens' => 500,
            'temperature' => 0.35,
            'response_format' => ['type' => 'json_object'],
        ];

        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ];
        if (trim($organization) !== '') {
            $headers[] = 'OpenAI-Organization: ' . trim($organization);
        }

        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => json_encode($payload, JSON_THROW_ON_ERROR),
                'timeout' => 60,
                'ignore_errors' => true,
            ],
        ]);

        $raw = @file_get_contents(self::ENDPOINT, false, $ctx);
        if ($raw === false) {
            throw new \RuntimeException('Could not reach the OpenAI API.');
        }

        $json = json_decode($raw, true);
        if (!is_array($json)) {
            throw new \RuntimeException('Unexpected response from OpenAI.');
        }
        if (isset($json['error'])) {
            $msg = is_array($json['error']) ? (string) ($json['error']['message'] ?? 'API error') : (string) $json['error'];

            throw new \RuntimeException('OpenAI: ' . $msg);
        }
        $text = $json['choices'][0]['message']['content'] ?? null;
        if (!is_string($text) || trim($text) === '') {
            throw new \RuntimeException('OpenAI returned an empty reply.');
        }

        $decoded = json_decode(trim($text), true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('OpenAI did not return valid JSON.');
        }
        $list = $decoded['keywords'] ?? null;
        if (!is_array($list)) {
            return [];
        }

        return KeywordPhraseNormalizer::normalizeList($list);
    }
}
