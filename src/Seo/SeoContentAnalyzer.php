<?php

declare(strict_types=1);

namespace App\Seo;

/**
 * Yoast-style SEO and readability analysis for admin editors.
 */
final class SeoContentAnalyzer
{
    /**
     * @param array{
     *   title: string,
     *   slug?: string,
     *   seo_title?: string,
     *   seo_description?: string,
     *   focus_keyphrase?: string,
     *   content?: string,
     *   content_plain?: string
     * } $input
     *
     * @return array{
     *   seo_score: int,
     *   readability_score: int,
     *   seo_checks: list<array{id: string, label: string, status: string, message: string}>,
     *   readability_checks: list<array{id: string, label: string, status: string, message: string}>
     * }
     */
    public function analyze(array $input): array
    {
        $title = trim($input['title'] ?? '');
        $slug = trim($input['slug'] ?? '');
        $seoTitle = trim($input['seo_title'] ?? '');
        $seoDesc = trim($input['seo_description'] ?? '');
        $keyphrase = self::normalizeKeyphrase($input['focus_keyphrase'] ?? '');
        $plain = trim($input['content_plain'] ?? '');
        if ($plain === '' && isset($input['content'])) {
            $plain = self::htmlToPlain((string) $input['content']);
        }

        $displayTitle = $seoTitle !== '' ? $seoTitle : $title;
        $seoChecks = [];
        $readChecks = [];

        // --- SEO checks ---
        $seoChecks[] = $this->checkTitleLength($displayTitle);
        $seoChecks[] = $this->checkMetaDescription($seoDesc, $plain);
        $seoChecks[] = $this->checkSlugLength($slug);

        if ($keyphrase !== '') {
            $seoChecks[] = $this->checkKeyphraseInTitle($keyphrase, $displayTitle);
            $seoChecks[] = $this->checkKeyphraseInMeta($keyphrase, $seoDesc);
            $seoChecks[] = $this->checkKeyphraseInSlug($keyphrase, $slug);
            $seoChecks[] = $this->checkKeyphraseInContent($keyphrase, $plain);
            $seoChecks[] = $this->checkKeyphraseInIntro($keyphrase, $plain);
            $seoChecks[] = $this->checkKeyphraseDensity($keyphrase, $plain);
        } else {
            $seoChecks[] = [
                'id' => 'keyphrase_set',
                'label' => 'Focus keyphrase',
                'status' => 'na',
                'message' => 'Set a focus keyphrase to run keyword checks.',
            ];
        }

        $seoChecks[] = $this->checkOutboundLinks((string) ($input['content'] ?? $plain));
        $seoChecks[] = $this->checkImagesAlt($input['content'] ?? $plain);

        // --- Readability checks ---
        $readChecks[] = $this->checkTextLength($plain);
        $readChecks[] = $this->checkSentenceLength($plain);
        $readChecks[] = $this->checkParagraphLength($plain);
        $readChecks[] = $this->checkSubheadings($input['content'] ?? $plain);
        $readChecks[] = $this->checkPassiveVoice($plain);
        $readChecks[] = $this->checkTransitionWords($plain);
        $readChecks[] = $this->checkFlesch($plain);

        return [
            'seo_score' => self::scoreFromChecks($seoChecks),
            'readability_score' => self::scoreFromChecks($readChecks),
            'seo_checks' => $seoChecks,
            'readability_checks' => $readChecks,
        ];
    }

    /**
     * @param list<array{status: string}> $checks
     */
    private static function scoreFromChecks(array $checks): int
    {
        $scored = array_filter($checks, static fn (array $c): bool => ($c['status'] ?? 'na') !== 'na');
        if ($scored === []) {
            return 0;
        }
        $points = 0;
        $max = 0;
        foreach ($scored as $c) {
            ++$max;
            $points += match ($c['status']) {
                'good' => 1,
                'ok' => 0.5,
                default => 0,
            };
        }

        return (int) round(($points / $max) * 100);
    }

    /**
     * @return array{id: string, label: string, status: string, message: string}
     */
    private function checkTitleLength(string $title): array
    {
        $len = mb_strlen($title);
        if ($title === '') {
            return ['id' => 'title_length', 'label' => 'SEO title length', 'status' => 'bad', 'message' => 'Add a title for search results.'];
        }
        if ($len >= 50 && $len <= 60) {
            return ['id' => 'title_length', 'label' => 'SEO title length', 'status' => 'good', 'message' => "Title length ({$len} chars) is in the ideal range."];
        }
        if ($len < 50) {
            return ['id' => 'title_length', 'label' => 'SEO title length', 'status' => 'ok', 'message' => "Title is short ({$len} chars). Aim for 50–60 characters."];
        }

        return ['id' => 'title_length', 'label' => 'SEO title length', 'status' => 'ok', 'message' => "Title may be truncated in search ({$len} chars). Aim for 50–60."];
    }

    /**
     * @return array{id: string, label: string, status: string, message: string}
     */
    private function checkMetaDescription(string $seoDesc, string $plain): array
    {
        $desc = $seoDesc !== '' ? $seoDesc : self::excerpt($plain, 160);
        $len = mb_strlen($desc);
        if ($desc === '') {
            return ['id' => 'meta_description', 'label' => 'Meta description', 'status' => 'bad', 'message' => 'Write a meta description for the search snippet.'];
        }
        if ($len >= 120 && $len <= 160) {
            return ['id' => 'meta_description', 'label' => 'Meta description', 'status' => 'good', 'message' => "Description length ({$len} chars) looks good."];
        }
        if ($len < 120) {
            return ['id' => 'meta_description', 'label' => 'Meta description', 'status' => 'ok', 'message' => "Description is short ({$len} chars). Aim for 120–160."];
        }

        return ['id' => 'meta_description', 'label' => 'Meta description', 'status' => 'ok', 'message' => "Description may be truncated ({$len} chars). Aim for 120–160."];
    }

    /**
     * @return array{id: string, label: string, status: string, message: string}
     */
    private function checkSlugLength(string $slug): array
    {
        if ($slug === '') {
            return ['id' => 'slug', 'label' => 'URL slug', 'status' => 'ok', 'message' => 'Slug will be generated from the title.'];
        }
        $len = mb_strlen($slug);
        if ($len <= 75) {
            return ['id' => 'slug', 'label' => 'URL slug', 'status' => 'good', 'message' => 'Slug length is fine for URLs.'];
        }

        return ['id' => 'slug', 'label' => 'URL slug', 'status' => 'ok', 'message' => 'Consider a shorter slug for cleaner URLs.'];
    }

    /**
     * @return array{id: string, label: string, status: string, message: string}
     */
    private function checkKeyphraseInTitle(string $kp, string $title): array
    {
        if (self::containsPhrase($title, $kp)) {
            return ['id' => 'kp_title', 'label' => 'Keyphrase in title', 'status' => 'good', 'message' => 'Focus keyphrase appears in the SEO title.'];
        }

        return ['id' => 'kp_title', 'label' => 'Keyphrase in title', 'status' => 'bad', 'message' => 'Use the focus keyphrase in the SEO title.'];
    }

    /**
     * @return array{id: string, label: string, status: string, message: string}
     */
    private function checkKeyphraseInMeta(string $kp, string $desc): array
    {
        if ($desc === '') {
            return ['id' => 'kp_meta', 'label' => 'Keyphrase in meta description', 'status' => 'bad', 'message' => 'Add a meta description containing the keyphrase.'];
        }
        if (self::containsPhrase($desc, $kp)) {
            return ['id' => 'kp_meta', 'label' => 'Keyphrase in meta description', 'status' => 'good', 'message' => 'Focus keyphrase appears in the meta description.'];
        }

        return ['id' => 'kp_meta', 'label' => 'Keyphrase in meta description', 'status' => 'bad', 'message' => 'Include the focus keyphrase in the meta description.'];
    }

    /**
     * @return array{id: string, label: string, status: string, message: string}
     */
    private function checkKeyphraseInSlug(string $kp, string $slug): array
    {
        if ($slug === '') {
            return ['id' => 'kp_slug', 'label' => 'Keyphrase in URL', 'status' => 'ok', 'message' => 'Slug not set yet — ensure it includes the keyphrase.'];
        }
        $slugNorm = str_replace('-', ' ', $slug);
        if (self::containsPhrase($slugNorm, $kp)) {
            return ['id' => 'kp_slug', 'label' => 'Keyphrase in URL', 'status' => 'good', 'message' => 'Focus keyphrase appears in the URL slug.'];
        }

        return ['id' => 'kp_slug', 'label' => 'Keyphrase in URL', 'status' => 'ok', 'message' => 'Consider including the keyphrase in the URL slug.'];
    }

    /**
     * @return array{id: string, label: string, status: string, message: string}
     */
    private function checkKeyphraseInContent(string $kp, string $plain): array
    {
        if ($plain === '') {
            return ['id' => 'kp_content', 'label' => 'Keyphrase in content', 'status' => 'bad', 'message' => 'Add body content that uses the focus keyphrase.'];
        }
        if (self::containsPhrase($plain, $kp)) {
            return ['id' => 'kp_content', 'label' => 'Keyphrase in content', 'status' => 'good', 'message' => 'Focus keyphrase appears in the content.'];
        }

        return ['id' => 'kp_content', 'label' => 'Keyphrase in content', 'status' => 'bad', 'message' => 'Use the focus keyphrase in the body text.'];
    }

    /**
     * @return array{id: string, label: string, status: string, message: string}
     */
    private function checkKeyphraseInIntro(string $kp, string $plain): array
    {
        if ($plain === '') {
            return ['id' => 'kp_intro', 'label' => 'Keyphrase in introduction', 'status' => 'na', 'message' => 'No content yet.'];
        }
        $intro = mb_substr($plain, 0, 300);
        if (self::containsPhrase($intro, $kp)) {
            return ['id' => 'kp_intro', 'label' => 'Keyphrase in introduction', 'status' => 'good', 'message' => 'Keyphrase appears near the start of the content.'];
        }

        return ['id' => 'kp_intro', 'label' => 'Keyphrase in introduction', 'status' => 'ok', 'message' => 'Use the keyphrase in the first paragraph.'];
    }

    /**
     * @return array{id: string, label: string, status: string, message: string}
     */
    private function checkKeyphraseDensity(string $kp, string $plain): array
    {
        if ($plain === '') {
            return ['id' => 'kp_density', 'label' => 'Keyphrase density', 'status' => 'na', 'message' => 'No content yet.'];
        }
        $words = self::wordCount($plain);
        if ($words < 50) {
            return ['id' => 'kp_density', 'label' => 'Keyphrase density', 'status' => 'na', 'message' => 'Add more content before checking density.'];
        }
        $count = self::phraseCount($plain, $kp);
        $density = ($count / $words) * 100;
        if ($density >= 0.5 && $density <= 2.5) {
            return ['id' => 'kp_density', 'label' => 'Keyphrase density', 'status' => 'good', 'message' => sprintf('Keyphrase density is %.1f%% (healthy range).', $density)];
        }
        if ($density < 0.5) {
            return ['id' => 'kp_density', 'label' => 'Keyphrase density', 'status' => 'ok', 'message' => sprintf('Keyphrase density is low (%.1f%%). Use it a few more times naturally.', $density)];
        }

        return ['id' => 'kp_density', 'label' => 'Keyphrase density', 'status' => 'ok', 'message' => sprintf('Keyphrase density is high (%.1f%%). Avoid keyword stuffing.', $density)];
    }

    /**
     * @return array{id: string, label: string, status: string, message: string}
     */
    private function checkOutboundLinks(string $plain): array
    {
        if ($plain === '') {
            return ['id' => 'links', 'label' => 'Links', 'status' => 'na', 'message' => 'No content yet.'];
        }
        $hasInternal = preg_match('#href=["\']/[^"\']+#i', $plain) === 1;
        $hasExternal = preg_match('#href=["\']https?://#i', $plain) === 1;
        if ($hasInternal || $hasExternal) {
            return ['id' => 'links', 'label' => 'Links', 'status' => 'good', 'message' => 'Content includes links (good for SEO and readers).'];
        }

        return ['id' => 'links', 'label' => 'Links', 'status' => 'ok', 'message' => 'Consider adding internal or external links.'];
    }

    /**
     * @return array{id: string, label: string, status: string, message: string}
     */
    private function checkImagesAlt(string $html): array
    {
        if (!str_contains($html, '<img')) {
            return ['id' => 'image_alt', 'label' => 'Image alt text', 'status' => 'na', 'message' => 'No images in content.'];
        }
        if (preg_match_all('#<img\b[^>]*>#i', $html, $imgs) === false || $imgs[0] === []) {
            return ['id' => 'image_alt', 'label' => 'Image alt text', 'status' => 'na', 'message' => 'No images in content.'];
        }
        $missing = 0;
        foreach ($imgs[0] as $tag) {
            if (!preg_match('#\balt=["\']([^"\']+)["\']#i', $tag, $m) || trim($m[1]) === '') {
                ++$missing;
            }
        }
        if ($missing === 0) {
            return ['id' => 'image_alt', 'label' => 'Image alt text', 'status' => 'good', 'message' => 'All images have alt text.'];
        }

        return ['id' => 'image_alt', 'label' => 'Image alt text', 'status' => 'bad', 'message' => "{$missing} image(s) missing alt text."];
    }

    /**
     * @return array{id: string, label: string, status: string, message: string}
     */
    private function checkTextLength(string $plain): array
    {
        $words = self::wordCount($plain);
        if ($words >= 300) {
            return ['id' => 'text_length', 'label' => 'Text length', 'status' => 'good', 'message' => "{$words} words — good length for SEO."];
        }
        if ($words >= 150) {
            return ['id' => 'text_length', 'label' => 'Text length', 'status' => 'ok', 'message' => "{$words} words — consider adding more depth (300+)."];
        }
        if ($words === 0) {
            return ['id' => 'text_length', 'label' => 'Text length', 'status' => 'bad', 'message' => 'Add body content for readability analysis.'];
        }

        return ['id' => 'text_length', 'label' => 'Text length', 'status' => 'bad', 'message' => "Only {$words} words — expand the content."];
    }

    /**
     * @return array{id: string, label: string, status: string, message: string}
     */
    private function checkSentenceLength(string $plain): array
    {
        $sentences = self::splitSentences($plain);
        if ($sentences === []) {
            return ['id' => 'sentence_length', 'label' => 'Sentence length', 'status' => 'na', 'message' => 'No sentences yet.'];
        }
        $long = 0;
        foreach ($sentences as $s) {
            if (self::wordCount($s) > 20) {
                ++$long;
            }
        }
        $pct = ($long / count($sentences)) * 100;
        if ($pct <= 25) {
            return ['id' => 'sentence_length', 'label' => 'Sentence length', 'status' => 'good', 'message' => sprintf('%.0f%% of sentences are long — within guidelines.', $pct)];
        }

        return ['id' => 'sentence_length', 'label' => 'Sentence length', 'status' => 'ok', 'message' => sprintf('%.0f%% of sentences exceed 20 words. Shorten some sentences.', $pct)];
    }

    /**
     * @return array{id: string, label: string, status: string, message: string}
     */
    private function checkParagraphLength(string $plain): array
    {
        $paras = preg_split('/\n\s*\n/', $plain) ?: [];
        $paras = array_filter(array_map('trim', $paras));
        if ($paras === []) {
            return ['id' => 'paragraph_length', 'label' => 'Paragraph length', 'status' => 'na', 'message' => 'No paragraphs yet.'];
        }
        $long = 0;
        foreach ($paras as $p) {
            if (self::wordCount($p) > 150) {
                ++$long;
            }
        }
        if ($long === 0) {
            return ['id' => 'paragraph_length', 'label' => 'Paragraph length', 'status' => 'good', 'message' => 'Paragraphs are a readable length.'];
        }

        return ['id' => 'paragraph_length', 'label' => 'Paragraph length', 'status' => 'ok', 'message' => "{$long} paragraph(s) are very long — break them up."];
    }

    /**
     * @return array{id: string, label: string, status: string, message: string}
     */
    private function checkSubheadings(string $html): array
    {
        $words = self::wordCount(self::htmlToPlain($html));
        if ($words < 300) {
            return ['id' => 'subheadings', 'label' => 'Subheadings', 'status' => 'na', 'message' => 'Not enough content to require subheadings.'];
        }
        $count = 0;
        if (preg_match_all('#<h[2-4]\b#i', $html, $m) !== false) {
            $count = count($m[0]);
        }
        if ($count >= 1) {
            return ['id' => 'subheadings', 'label' => 'Subheadings', 'status' => 'good', 'message' => 'Content uses subheadings to break up text.'];
        }

        return ['id' => 'subheadings', 'label' => 'Subheadings', 'status' => 'ok', 'message' => 'Add H2/H3 subheadings for longer content.'];
    }

    /**
     * @return array{id: string, label: string, status: string, message: string}
     */
    private function checkPassiveVoice(string $plain): array
    {
        $sentences = self::splitSentences($plain);
        if ($sentences === []) {
            return ['id' => 'passive_voice', 'label' => 'Passive voice', 'status' => 'na', 'message' => 'No sentences yet.'];
        }
        $passivePattern = '/\b(am|is|are|was|were|be|been|being)\s+\w+(ed|en)\b/i';
        $passive = 0;
        foreach ($sentences as $s) {
            if (preg_match($passivePattern, $s) === 1) {
                ++$passive;
            }
        }
        $pct = ($passive / count($sentences)) * 100;
        if ($pct <= 10) {
            return ['id' => 'passive_voice', 'label' => 'Passive voice', 'status' => 'good', 'message' => sprintf('%.0f%% passive sentences — active voice dominates.', $pct)];
        }

        return ['id' => 'passive_voice', 'label' => 'Passive voice', 'status' => 'ok', 'message' => sprintf('%.0f%% of sentences may use passive voice. Prefer active voice.', $pct)];
    }

    /**
     * @return array{id: string, label: string, status: string, message: string}
     */
    private function checkTransitionWords(string $plain): array
    {
        $sentences = self::splitSentences($plain);
        if (count($sentences) < 3) {
            return ['id' => 'transition_words', 'label' => 'Transition words', 'status' => 'na', 'message' => 'Not enough sentences.'];
        }
        $transitions = 'however|therefore|moreover|furthermore|additionally|also|because|although|instead|meanwhile|finally|first|second|next|then|for example|in addition|as a result';
        $with = 0;
        foreach ($sentences as $s) {
            if (preg_match('/\b(' . $transitions . ')\b/i', $s) === 1) {
                ++$with;
            }
        }
        $pct = ($with / count($sentences)) * 100;
        if ($pct >= 30) {
            return ['id' => 'transition_words', 'label' => 'Transition words', 'status' => 'good', 'message' => sprintf('%.0f%% of sentences use transition words.', $pct)];
        }

        return ['id' => 'transition_words', 'label' => 'Transition words', 'status' => 'ok', 'message' => 'Use more transition words (however, therefore, for example…).'];
    }

    /**
     * @return array{id: string, label: string, status: string, message: string}
     */
    private function checkFlesch(string $plain): array
    {
        $words = self::wordCount($plain);
        if ($words < 50) {
            return ['id' => 'flesch', 'label' => 'Flesch reading ease', 'status' => 'na', 'message' => 'Add more text for a readability score.'];
        }
        $sentences = max(1, count(self::splitSentences($plain)));
        $syllables = self::estimateSyllables($plain);
        $score = 206.835 - 1.015 * ($words / $sentences) - 84.6 * ($syllables / $words);
        $score = max(0, min(100, $score));
        if ($score >= 60) {
            return ['id' => 'flesch', 'label' => 'Flesch reading ease', 'status' => 'good', 'message' => sprintf('Score %.0f — fairly easy to read.', $score)];
        }
        if ($score >= 50) {
            return ['id' => 'flesch', 'label' => 'Flesch reading ease', 'status' => 'ok', 'message' => sprintf('Score %.0f — moderately difficult. Simplify where possible.', $score)];
        }

        return ['id' => 'flesch', 'label' => 'Flesch reading ease', 'status' => 'ok', 'message' => sprintf('Score %.0f — hard to read. Use shorter words and sentences.', $score)];
    }

    private static function normalizeKeyphrase(string $phrase): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $phrase) ?? ''));
    }

    private static function containsPhrase(string $haystack, string $phrase): bool
    {
        if ($phrase === '') {
            return false;
        }

        return mb_strpos(mb_strtolower($haystack), $phrase) !== false;
    }

    private static function phraseCount(string $text, string $phrase): int
    {
        if ($phrase === '') {
            return 0;
        }
        $count = 0;
        $lower = mb_strtolower($text);
        $offset = 0;
        while (($pos = mb_strpos($lower, $phrase, $offset)) !== false) {
            ++$count;
            $offset = $pos + mb_strlen($phrase);
        }

        return $count;
    }

    private static function wordCount(string $text): int
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
        if ($text === '') {
            return 0;
        }
        $parts = preg_split('/\s+/u', $text);

        return $parts === false ? 0 : count($parts);
    }

    /**
     * @return list<string>
     */
    private static function splitSentences(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }
        $parts = preg_split('/(?<=[.!?])\s+/u', $text) ?: [];

        return array_values(array_filter(array_map('trim', $parts)));
    }

    private static function htmlToPlain(string $html): string
    {
        $plain = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>', '</li>'], "\n", $html));

        return trim(preg_replace('/\s+/', ' ', $plain) ?? '');
    }

    private static function excerpt(string $plain, int $max): string
    {
        if (mb_strlen($plain) <= $max) {
            return $plain;
        }

        return mb_substr($plain, 0, $max - 1) . '…';
    }

    private static function estimateSyllables(string $text): int
    {
        $words = preg_split('/\s+/u', mb_strtolower($text)) ?: [];
        $total = 0;
        foreach ($words as $w) {
            $w = preg_replace('/[^a-z]/', '', $w) ?? '';
            if ($w === '') {
                continue;
            }
            $v = preg_match_all('/[aeiouy]+/', $w, $m);
            $total += max(1, $v !== false ? $v : 1);
        }

        return max(1, $total);
    }
}
