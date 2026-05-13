<?php

declare(strict_types=1);

namespace App\Ai;

use App\Comment\CommentValidator;

/**
 * Uses OpenAI to draft short public comments for published content entries (e.g. blog posts).
 */
final class AiSyntheticCommentsGenerator
{
    public function __construct(
        private readonly OpenAiChatClient $client = new OpenAiChatClient()
    ) {
    }

    /**
     * @param list<array{id: int, title: string, slug: string, type_slug: string}> $articles
     * @return list<array{entry_id: int, author_name: string, body: string}>
     */
    public function generate(
        string $apiKey,
        string $model,
        array $articles,
        string $sentiment,
        int $perArticle,
        bool $imperfectWriting
    ): array {
        if ($articles === []) {
            return [];
        }
        $perArticle = max(1, min(4, $perArticle));
        $sentiment = $this->normalizeSentiment($sentiment);

        $lines = [];
        foreach ($articles as $a) {
            $lines[] = '- id ' . $a['id'] . ': "' . $this->oneLine($a['title']) . '" (slug: ' . $a['slug'] . ')';
        }
        $articleBlock = implode("\n", $lines);

        $imperfectBlock = $imperfectWriting
            ? 'For roughly 30–40% of the comments only (pick at random which rows), make the tone slightly rougher: '
            . 'occasional small typo or missing apostrophe, sometimes skip the final full stop, keep it believable. '
            . 'The other comments should read clean and natural. Set "imperfect": true only on those rougher rows.'
            : 'Every comment should read clean: normal punctuation and spelling. Set "imperfect": false on every row.';

        $sentimentLine = match ($sentiment) {
            'positive' => 'All comments should be clearly positive or appreciative.',
            'negative' => 'All comments should be clearly critical or skeptical (stay civil, no slurs or harassment).',
            'neutral' => 'All comments should be neutral or matter-of-fact.',
            default => 'Vary sentiment across comments: some positive, some neutral, some mildly negative (all civil).',
        };

        $system = 'You write short authentic-looking reader comments for a CMS. '
            . 'Output ONLY valid JSON, no markdown fences, no commentary. '
            . 'Use generic plausible display names (e.g. sam_k, reader42, m_jones88) — not real celebrity names. '
            . 'Comments must sound like real people skimming a blog: concise (1–4 sentences), specific to the title where possible.';

        $user = "Articles (each comment thread uses entry:{id} on the site):\n"
            . $articleBlock . "\n\n"
            . 'Generate exactly ' . $perArticle . ' distinct comment(s) per article listed (total '
            . (count($articles) * $perArticle) . ' comments). '
            . $sentimentLine . ' '
            . $imperfectBlock . "\n\n"
            . 'JSON shape (array only, one object per comment): '
            . '{"comments":[{"entry_id":<int>,"author_name":"<string max 120>","body":"<string>","imperfect":<bool>}]}';

        $raw = $this->client->chatJsonObject(
            $apiKey,
            $model,
            [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
            0.85
        );

        $decoded = $this->decodeJsonObject($raw);
        $list = $decoded['comments'] ?? null;
        if (!is_array($list)) {
            throw new OpenAiException('Model JSON did not contain a "comments" array.');
        }

        $allowedIds = [];
        foreach ($articles as $a) {
            $allowedIds[(int) $a['id']] = true;
        }

        $out = [];
        foreach ($list as $row) {
            if (!is_array($row)) {
                continue;
            }
            $eid = isset($row['entry_id']) && is_numeric($row['entry_id']) ? (int) $row['entry_id'] : 0;
            if ($eid < 1 || !isset($allowedIds[$eid])) {
                continue;
            }
            $name = isset($row['author_name']) && is_string($row['author_name']) ? trim($row['author_name']) : '';
            $body = isset($row['body']) && is_string($row['body']) ? trim($row['body']) : '';
            if ($name === '' || $body === '') {
                continue;
            }
            if (strlen($name) > CommentValidator::MAX_NAME) {
                $name = substr($name, 0, CommentValidator::MAX_NAME);
            }
            if (strlen($body) > CommentValidator::MAX_BODY) {
                $body = substr($body, 0, CommentValidator::MAX_BODY);
            }
            $out[] = [
                'entry_id' => $eid,
                'author_name' => $name,
                'body' => $body,
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonObject(string $raw): array
    {
        $raw = trim($raw);
        if (preg_match('/```(?:json)?\s*(\{[\s\S]*\})\s*```/m', $raw, $m)) {
            $raw = $m[1];
        }
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new OpenAiException('Model returned invalid JSON: ' . $e->getMessage());
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function normalizeSentiment(string $s): string
    {
        $s = strtolower(trim($s));

        return in_array($s, ['positive', 'neutral', 'negative', 'mixed'], true) ? $s : 'mixed';
    }

    private function oneLine(string $s): string
    {
        $s = str_replace(["\r", "\n"], ' ', $s);

        return trim($s) === '' ? 'Untitled' : trim($s);
    }
}
