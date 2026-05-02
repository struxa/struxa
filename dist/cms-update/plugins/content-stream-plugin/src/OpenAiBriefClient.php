<?php

declare(strict_types=1);

namespace ContentStreamPlugin;

/**
 * Two-pass OpenAI flow: (1) verbatim evidence from fetched homepage text, (2) classification only from that evidence.
 * PHP post-validation drops sector labels unsupported by on-page text.
 */
final class OpenAiBriefClient
{
    private const ENDPOINT = 'https://api.openai.com/v1/chat/completions';

    private const PROMPT_SNIPPET_MAX = 12_000;

    /**
     * @return array<string, mixed>
     *
     * @throws \RuntimeException on HTTP, API, or invalid JSON errors
     */
    public function domainBusinessAnalysis(string $apiKey, string $organization, string $model, string $domain): array
    {
        $model = trim($model) !== '' ? trim($model) : 'gpt-4o-mini';
        $domain = trim($domain);

        $fetch = HomepageTextFetcher::fetch($domain);
        $pageText = $fetch['text'];
        $snippet = $pageText;
        if (mb_strlen($snippet, 'UTF-8') > self::PROMPT_SNIPPET_MAX) {
            $snippet = mb_substr($snippet, 0, self::PROMPT_SNIPPET_MAX, 'UTF-8');
        }

        $pass1 = $this->passExtractEvidence($apiKey, $organization, $model, $domain, $snippet, $fetch['ok']);
        $rawEvidence = [];
        if (isset($pass1['evidence']) && is_array($pass1['evidence'])) {
            foreach ($pass1['evidence'] as $e) {
                if (is_string($e)) {
                    $rawEvidence[] = $e;
                }
            }
        }

        $evidence = BriefAnalysisValidator::filterEvidenceToSource($rawEvidence, $pageText);

        $pass2 = $this->passClassifyFromEvidence($apiKey, $organization, $model, $domain, $snippet, $evidence, $fetch['final_url']);

        $analysis = self::normalizeAnalysisShape($pass2, $domain, $fetch['final_url']);

        return BriefAnalysisValidator::enforce($analysis, $pageText, $evidence);
    }

    /**
     * @return array<string, mixed>
     */
    private function passExtractEvidence(
        string $apiKey,
        string $organization,
        string $model,
        string $domain,
        string $pageSnippet,
        bool $fetchOk,
    ): array {
        $fetchNote = $fetchOk
            ? 'Homepage HTML was fetched and reduced to plain text below.'
            : 'Homepage fetch failed or returned no usable body; the page text block may be empty.';

        $user = <<<TXT
Domain: {$domain}

{$fetchNote}

Plain page text (truncated for this request; evidence quotes MUST be copied verbatim from this block only):
---
{$pageSnippet}
---

Return a single JSON object with exactly these keys:
- "evidence": array of strings. Each string MUST be copied character-for-character as a contiguous substring from the plain page text block above (not paraphrased). Length between 12 and 400 characters each. Prefer sentences or phrases that state what the company does, who it serves, or product names. If there is no usable marketing copy (empty text, only "loading", login wall with no product description, or wrong page), return [].
- "notes": string — one short sentence for operators only (e.g. "login wall" or "sparse hero"); do not invent facts here.

Rules:
- Do NOT infer industry or customer type in this pass — only extract literal text.
- Do not copy boilerplate alone ("copyright 2025", cookie banner only) unless it is the only content; prefer product/value statements.
- Maximum 24 evidence strings.
TXT;

        $messages = [
            [
                'role' => 'system',
                'content' => 'You extract verbatim quotes from supplied website text for downstream classification. '
                    . 'Output only valid JSON. Never invent quotes; evidence strings must exist exactly inside the provided text block.',
            ],
            ['role' => 'user', 'content' => $user],
        ];

        return $this->completeJsonObject($apiKey, $organization, $model, $messages, 1_800, 0.15);
    }

    /**
     * @param list<string> $evidence
     *
     * @return array<string, mixed>
     */
    private function passClassifyFromEvidence(
        string $apiKey,
        string $organization,
        string $model,
        string $domain,
        string $pageSnippet,
        array $evidence,
        string $finalUrl,
    ): array {
        $evidenceJson = json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $urlHint = $finalUrl !== '' ? $finalUrl : ('https://' . $domain . '/');

        $user = <<<TXT
Domain: {$domain}
Canonical URL (if fetch worked): {$urlHint}

Evidence array (verbatim substrings from the real homepage; may be empty):
{$evidenceJson}

Same page text excerpt (for cross-check only; do not quote new phrases that are not already in the evidence array):
---
{$pageSnippet}
---

Return ONE JSON object with exactly these keys (arrays where noted):
- business_name (string): from evidence if stated; otherwise a neutral brand-style title derived only from the domain string (no sector claims).
- website_url (string): full URL; use "{$urlHint}" if none stated in evidence.
- business_summary (string): one short paragraph using ONLY claims supportable by the evidence strings and/or the excerpt. If evidence is empty, write exactly: "No on-page evidence was extracted; sector and offerings are not classified."
- vertical_niche (string): specific sub-industry line ONLY if explicitly supported by evidence/excerpt; otherwise "unknown".
- industry (string): ONLY if explicitly supported by evidence/excerpt; otherwise "unknown".
- business_type (string): exactly one of: local_service, ecommerce, saas, affiliate, content_site, agency, marketplace, other, unknown
- target_audience (array of strings): only roles/types literally supported; else []
- primary_use_cases (array of strings): else []
- core_products_or_services (array of strings): else []
- locations_served (array of strings): else []
- brand_tone (string): short, from on-page voice if clear; else "unknown"
- top_seo_topics (array): up to 10 strings ONLY if industry is not "unknown"; else []
- seed_keywords (array): up to 15 strings ONLY if industry is not "unknown"; else []

Hard rules:
1) NEVER assign construction, healthcare, finance, legal, hospitality, hotels, restaurants, medical, banking, insurance, attorney, real estate, automotive, manufacturing, pharma, or similar sectors unless those words (or unambiguous direct synonyms appearing ON THE PAGE) appear inside the evidence strings or the excerpt.
2) Do not infer vertical from the domain name alone (e.g. invented "construction" from a brand word).
3) If evidence is empty, set industry and vertical_niche to "unknown", business_type to "unknown", and leave all arrays empty except business_name and website_url as instructed.
4) Do not add an "evidence" key (the application merges evidence separately).

TXT;

        $messages = [
            [
                'role' => 'system',
                'content' => 'You classify a business using ONLY supplied evidence and page excerpt. '
                    . 'You must not hallucinate verticals. Prefer "unknown" and empty arrays over a wrong industry. '
                    . 'Output only valid JSON matching the user schema.',
            ],
            ['role' => 'user', 'content' => $user],
        ];

        return $this->completeJsonObject($apiKey, $organization, $model, $messages, 3_200, 0.2);
    }

    /**
     * @param list<array{role: string, content: string}> $messages
     *
     * @return array<string, mixed>
     */
    private function completeJsonObject(
        string $apiKey,
        string $organization,
        string $model,
        array $messages,
        int $maxTokens,
        float $temperature,
    ): array {
        $payload = [
            'model' => $model,
            'messages' => $messages,
            'max_tokens' => $maxTokens,
            'temperature' => $temperature,
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
                'timeout' => 120,
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

        return self::decodeModelJsonObject($text);
    }

    /**
     * @param array<string, mixed> $decoded
     *
     * @return array<string, mixed>
     */
    private static function normalizeAnalysisShape(array $decoded, string $domain, string $finalUrl): array
    {
        $url = $finalUrl !== '' ? $finalUrl : ('https://' . $domain . '/');

        return [
            'business_name' => is_string($decoded['business_name'] ?? null) ? trim((string) $decoded['business_name']) : self::titleFromDomain($domain),
            'website_url' => is_string($decoded['website_url'] ?? null) && trim((string) $decoded['website_url']) !== ''
                ? trim((string) $decoded['website_url'])
                : $url,
            'business_summary' => is_string($decoded['business_summary'] ?? null) ? trim((string) $decoded['business_summary']) : '',
            'vertical_niche' => is_string($decoded['vertical_niche'] ?? null) ? trim((string) $decoded['vertical_niche']) : 'unknown',
            'industry' => is_string($decoded['industry'] ?? null) ? trim((string) $decoded['industry']) : 'unknown',
            'business_type' => is_string($decoded['business_type'] ?? null) ? trim((string) $decoded['business_type']) : 'unknown',
            'target_audience' => self::stringList($decoded['target_audience'] ?? []),
            'primary_use_cases' => self::stringList($decoded['primary_use_cases'] ?? []),
            'core_products_or_services' => self::stringList($decoded['core_products_or_services'] ?? []),
            'locations_served' => self::stringList($decoded['locations_served'] ?? []),
            'brand_tone' => is_string($decoded['brand_tone'] ?? null) ? trim((string) $decoded['brand_tone']) : 'unknown',
            'top_seo_topics' => self::stringList($decoded['top_seo_topics'] ?? []),
            'seed_keywords' => self::stringList($decoded['seed_keywords'] ?? []),
        ];
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $v): array
    {
        if (!is_array($v)) {
            return [];
        }
        $out = [];
        foreach ($v as $item) {
            if (is_string($item)) {
                $t = trim($item);
                if ($t !== '') {
                    $out[] = $t;
                }
            }
        }

        return $out;
    }

    private static function titleFromDomain(string $domain): string
    {
        $base = preg_replace('/\.[a-z0-9.-]+$/i', '', strtolower($domain)) ?? $domain;
        $base = str_replace(['-', '_'], ' ', $base);

        return $base !== '' ? ucwords(trim($base)) : $domain;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeModelJsonObject(string $text): array
    {
        $t = trim($text);
        if (preg_match('/^```(?:json)?\s*(.*)\s*```$/s', $t, $m)) {
            $t = trim($m[1]);
        }
        $decoded = json_decode($t, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('OpenAI did not return valid JSON.');
        }

        return $decoded;
    }
}
