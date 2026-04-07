<?php

declare(strict_types=1);

namespace ContentStreamPlugin;

/**
 * Filters model-supplied evidence against source text and blocks vertical claims unsupported by on-page text.
 */
final class BriefAnalysisValidator
{
    /** Multi-word or high-risk vertical tokens; must appear in page+evidence haystack to keep sector labels. */
    private const SENSITIVE_VERTICAL_MARKERS = [
        'construction',
        'healthcare',
        'health care',
        'medical',
        'hospital',
        'clinic',
        'pharma',
        'finance',
        'financial',
        'fintech',
        'banking',
        'insurance',
        'legal',
        'attorney',
        'law firm',
        'hospitality',
        'hotel',
        'restaurant',
        'café',
        'cafe',
        'realtor',
        'real estate',
        'automotive',
        'manufacturing',
    ];

    /**
     * @param list<string> $evidence
     *
     * @return list<string>
     */
    public static function filterEvidenceToSource(array $evidence, string $pageText): array
    {
        if ($pageText === '') {
            return [];
        }
        $out = [];
        foreach ($evidence as $e) {
            if (!is_string($e)) {
                continue;
            }
            $e = trim($e);
            if (mb_strlen($e, 'UTF-8') < 12 || mb_strlen($e, 'UTF-8') > 400) {
                continue;
            }
            if (mb_stripos($pageText, $e, 0, 'UTF-8') !== false) {
                $out[] = $e;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param array<string, mixed> $analysis
     * @param list<string>         $validatedEvidence
     *
     * @return array<string, mixed>
     */
    public static function enforce(array $analysis, string $pageText, array $validatedEvidence): array
    {
        $analysis['evidence'] = $validatedEvidence;

        $hay = mb_strtolower($pageText . "\n" . implode("\n", $validatedEvidence), 'UTF-8');

        if ($validatedEvidence === []) {
            return self::unknownProfile($analysis, $pageText === '' ? 'no_page_text' : 'no_verbatim_evidence');
        }

        $industry = mb_strtolower((string) ($analysis['industry'] ?? ''), 'UTF-8');
        $niche = mb_strtolower((string) ($analysis['vertical_niche'] ?? ''), 'UTF-8');
        $summary = mb_strtolower((string) ($analysis['business_summary'] ?? ''), 'UTF-8');
        $blob = $industry . ' ' . $niche . ' ' . $summary;

        foreach (self::SENSITIVE_VERTICAL_MARKERS as $term) {
            $t = mb_strtolower($term, 'UTF-8');
            if ($t === '') {
                continue;
            }
            if (str_contains($blob, $t) && !str_contains($hay, $t)) {
                return self::unknownProfile($analysis, 'vertical_not_in_evidence:' . $t);
            }
        }

        if (!self::nicheSupportedByHaystack((string) ($analysis['vertical_niche'] ?? ''), $hay)) {
            $analysis['vertical_niche'] = 'unknown';
        }
        if (!self::nicheSupportedByHaystack((string) ($analysis['industry'] ?? ''), $hay)) {
            $analysis['industry'] = 'unknown';
        }

        if (
            ($analysis['vertical_niche'] ?? '') === 'unknown'
            && ($analysis['industry'] ?? '') === 'unknown'
        ) {
            $analysis['business_summary'] = self::clampSummaryToEvidence(
                (string) ($analysis['business_summary'] ?? ''),
                $validatedEvidence,
                $pageText
            );
            $analysis['top_seo_topics'] = [];
            $analysis['seed_keywords'] = [];
        }

        return $analysis;
    }

    private static function nicheSupportedByHaystack(string $field, string $hay): bool
    {
        $field = trim($field);
        if ($field === '' || mb_strtolower($field, 'UTF-8') === 'unknown') {
            return true;
        }
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($field, 'UTF-8')) ?: [];
        $significant = [];
        foreach ($words as $w) {
            if (mb_strlen($w, 'UTF-8') >= 5) {
                $significant[] = $w;
            }
        }
        if ($significant === []) {
            return true;
        }
        foreach ($significant as $w) {
            if (!str_contains($hay, $w)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $analysis
     *
     * @return array<string, mixed>
     */
    private static function unknownProfile(array $analysis, string $reason): array
    {
        $analysis['industry'] = 'unknown';
        $analysis['vertical_niche'] = 'unknown';
        $analysis['business_type'] = 'unknown';
        $analysis['business_summary'] = 'Insufficient on-page evidence to infer sector or positioning (' . $reason . '). '
            . 'Add more crawlable marketing copy on the homepage, or paste positioning text into a future version of this tool.';
        $analysis['target_audience'] = [];
        $analysis['primary_use_cases'] = [];
        $analysis['core_products_or_services'] = [];
        $analysis['locations_served'] = [];
        $analysis['brand_tone'] = 'unknown';
        $analysis['top_seo_topics'] = [];
        $analysis['seed_keywords'] = [];

        return $analysis;
    }

    /**
     * @param list<string> $evidence
     */
    private static function clampSummaryToEvidence(string $summary, array $evidence, string $pageText): string
    {
        $summary = trim($summary);
        if ($summary === '') {
            return 'See on-page evidence snippets below; specific industry labels were not supported by the extracted text.';
        }
        $hay = mb_strtolower($pageText . "\n" . implode("\n", $evidence), 'UTF-8');
        $low = mb_strtolower($summary, 'UTF-8');
        $words = preg_split('/[^\p{L}\p{N}]+/u', $low) ?: [];
        $bad = false;
        foreach ($words as $w) {
            if (mb_strlen($w, 'UTF-8') < 6) {
                continue;
            }
            if (!str_contains($hay, $w)) {
                $bad = true;
                break;
            }
        }
        if ($bad) {
            return 'Summary contained claims not directly supported by the extracted page text; use the evidence field for what the site actually says.';
        }

        return $summary;
    }
}
