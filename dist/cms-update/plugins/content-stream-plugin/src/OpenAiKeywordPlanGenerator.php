<?php

declare(strict_types=1);

namespace ContentStreamPlugin;

/**
 * Builds a one-post-per-day calendar for a month from structured business analysis (JSON).
 */
final class OpenAiKeywordPlanGenerator
{
    private const ENDPOINT = 'https://api.openai.com/v1/chat/completions';

    /**
     * @param array<string, mixed> $analysis Domain analysis object (business_summary, seed_keywords, etc.)
     *
     * @return list<array<string, mixed>>
     *
     * @throws \RuntimeException
     */
    public function generate(
        string $apiKey,
        string $organization,
        string $model,
        array $analysis,
        int $daysInMonth,
    ): array {
        $model = trim($model) !== '' ? trim($model) : 'gpt-4o-mini';
        $daysInMonth = max(1, min(31, $daysInMonth));

        $blob = json_encode($analysis, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($blob === false) {
            throw new \RuntimeException('Could not encode analysis.');
        }
        if (strlen($blob) > 14000) {
            $blob = substr($blob, 0, 14000) . '…';
        }

        $schema = <<<TXT
Return a single JSON object with key "items" only: an array of exactly {$daysInMonth} objects, one per calendar day 1..{$daysInMonth} in order.
Each object must have:
- day: integer (1 to {$daysInMonth})
- primary_keyword: string (Google-style query, grounded in business_summary; max 80 chars)
- search_intent: string, one of informational, commercial, transactional, navigational
- title: string (compelling blog title, ≤70 chars if possible)
- outline: array of 4–7 strings (H2-style section headings — must fit that day’s article archetype)
- meta_description: string (≤160 chars, benefit-led)
- opportunity_score: number 0–100 (higher = better blend of demand fit, intent match, and uniqueness for THIS business)
- score_rationale: string (one sentence: why this post matters for the site)

EDITORIAL STRATEGY (obey strictly):
- The site OWNS the offerings in core_products_or_services and business_summary. Content should attract buyers of THOSE services/products or readers who will trust this brand — not send them shopping for competing vendors.
- If business_type is saas, ecommerce, or the business sells software/tools/platforms: FORBIDDEN calendar-wide patterns include "best [X] software", "top tools for", "top 10 apps", "software reviews" roundups, generic "compare [category] software" angles, or listicles that help readers pick third-party products instead of this business. Prefer owned POV: methodology, implementation playbooks, standards/regulatory explainers, sector-specific guidance, risk/safety, ROI frameworks, myths vs facts, FAQs, checklists, glossary deep-dives, "how we approach…", and case-style narratives that showcase this firm’s work without naming competitors.
- If the business is agency/local_service: still avoid repetitive "best tools" SEO filler; focus on problems the client solves, local/niche angles from locations_served, and consultation/education that converts to their offer — not affiliate-style tool roundups.

VERTICAL GROUNDING (obey strictly — stops generic "workflow software" calendars):
- Use vertical_niche, primary_use_cases, target_audience, industry, and locations_served from the analysis JSON only — never invent hospitality, STR, or cleaning angles unless those words or equivalents appear there. If vertical_niche or primary_use_cases is present, MOST posts must clearly serve THAT described niche. A reader should tell which industry this site is for from the title alone.
- BANNED as the dominant theme for the month: interchangeable titles that could apply to any B2B SaaS ("Unlocking workflow management", "Enhancing operational workflows", "Step-by-step guide to efficiency", "Benefits of management software") with no niche nouns taken from the analysis.
- primary_keyword and title must usually reuse concrete nouns and situations from business_summary, vertical_niche, or primary_use_cases — do not replace them with generic "operations" or "workflows" when the analysis already names a sector, customer, or problem.

DIVERSITY (obey strictly):
- Each day must use a DIFFERENT article archetype (rotate across the month). Examples to mix (do not repeat the same archetype on consecutive days): myth-busting, numbered checklist, step-by-step process, regulatory/standard explainer, glossary/definition deep-dive, "questions to ask before…", trend commentary with a clear POV, mini case narrative, technical deep-dive for practitioners, cost/schedule/risk framework, interview/FAQ style sections, sector spotlight — vary outline heading wording and structure; NEVER reuse a boilerplate like "Introduction / Key Features / Case Studies / Conclusion" across multiple posts.
- primary_keyword values must be clearly distinct: ban near-duplicates (same words reordered), and ban more than ~30% of posts clustering on one theme (e.g. "consultation" or "tools") unless the analysis shows an ultra-narrow niche — even then vary the problem framing.
- Vary intent mix (mostly informational). No duplicate primary_keyword.

Output: JSON only. No markdown in strings.
TXT;

        $payload = [
            'model' => $model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You are a senior content strategist and SEO editorial planner. You protect the brand: you do not draft competitor-bait listicles when the business sells the category. You maximize topical diversity and distinct article formats. Output only valid JSON (json_object). No prose outside the JSON.',
                ],
                [
                    'role' => 'user',
                    'content' => "Business analysis JSON:\n{$blob}\n\n{$schema}",
                ],
            ],
            'max_tokens' => 12000,
            'temperature' => 0.62,
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
                'timeout' => 180,
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
        $items = $decoded['items'] ?? null;
        if (!is_array($items)) {
            throw new \RuntimeException('OpenAI JSON missing "items" array.');
        }

        return self::normalizeItems($items, $daysInMonth);
    }

    /**
     * @param list<mixed> $items
     *
     * @return list<array<string, mixed>>
     */
    private static function normalizeItems(array $items, int $expectedDays): array
    {
        $byDay = [];
        foreach ($items as $row) {
            if (!is_array($row)) {
                continue;
            }
            $day = (int) ($row['day'] ?? 0);
            if ($day < 1 || $day > $expectedDays) {
                continue;
            }
            $kw = KeywordPhraseNormalizer::normalizeOne((string) ($row['primary_keyword'] ?? ''));
            $title = trim((string) ($row['title'] ?? ''));
            if ($kw === '' || $title === '') {
                continue;
            }
            $outline = $row['outline'] ?? [];
            $outlineList = [];
            if (is_array($outline)) {
                foreach ($outline as $h) {
                    if (is_string($h) && trim($h) !== '') {
                        $outlineList[] = trim($h);
                    }
                }
            }

            $scoreVal = null;
            if (is_numeric($row['opportunity_score'] ?? null)) {
                $v = (float) $row['opportunity_score'];
                if (is_finite($v)) {
                    $scoreVal = max(0, min(100, $v));
                }
            }

            $byDay[$day] = [
                'day' => $day,
                'primary_keyword' => $kw,
                'search_intent' => trim((string) ($row['search_intent'] ?? 'informational')),
                'title' => $title,
                'outline' => $outlineList,
                'meta_description' => trim((string) ($row['meta_description'] ?? '')),
                'opportunity_score' => $scoreVal,
                'score_rationale' => trim((string) ($row['score_rationale'] ?? '')),
            ];
        }

        ksort($byDay, SORT_NUMERIC);
        $out = array_values($byDay);
        if (count($out) < $expectedDays) {
            throw new \RuntimeException(
                'Expected ' . $expectedDays . ' post ideas, got ' . count($out) . '. Try again or shorten the month.'
            );
        }

        return array_slice($out, 0, $expectedDays);
    }
}
