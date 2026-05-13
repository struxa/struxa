<?php

declare(strict_types=1);

namespace AviosDestinationReviewPlugin;

use App\Ai\OpenAiApiKeyResolver;
use PDO;

/**
 * Singleton settings row in adr_settings (id = 1).
 *
 * @phpstan-type AdrSettings array{
 *   openai_api_key: string,
 *   openai_model: string,
 *   prompt_template: string,
 *   image_enabled: bool,
 *   image_model: string,
 *   image_size: string,
 *   image_prompt_template: string,
 *   api_key_stored: bool,
 *   openai_env_override: bool,
 *   updated_at: ?string
 * }
 */
final class SettingsRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function tableExists(): bool
    {
        try {
            $this->pdo->query('SELECT 1 FROM adr_settings LIMIT 1');

            return true;
        } catch (\PDOException) {
            return false;
        }
    }

    /**
     * @return AdrSettings
     */
    public function get(): array
    {
        $defaults = $this->defaults();
        if (!$this->tableExists()) {
            return $defaults;
        }
        $stmt = $this->pdo->query('SELECT * FROM adr_settings WHERE id = 1 LIMIT 1');
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return $defaults;
        }
        $prompt = (string) ($row['prompt_template'] ?? '');
        if (trim($prompt) === '') {
            $prompt = $defaults['prompt_template'];
        }
        $imgEnabled = (bool) (int) ($row['image_enabled'] ?? 0);
        $imgModel = trim((string) ($row['image_model'] ?? '')) ?: $defaults['image_model'];
        $imgSize = trim((string) ($row['image_size'] ?? '')) ?: $defaults['image_size'];
        $imgPrompt = (string) ($row['image_prompt_template'] ?? '');
        if (trim($imgPrompt) === '') {
            $imgPrompt = $defaults['image_prompt_template'];
        }

        return [
            'openai_api_key' => '',
            'openai_model' => OpenAiApiKeyResolver::activeModel(),
            'prompt_template' => $prompt,
            'image_enabled' => $imgEnabled,
            'image_model' => $imgModel,
            'image_size' => $imgSize,
            'image_prompt_template' => $imgPrompt,
            'api_key_stored' => OpenAiApiKeyResolver::resolve() !== '',
            'openai_env_override' => OpenAiApiKeyResolver::hasEnvApiKey(),
            'updated_at' => isset($row['updated_at']) ? (string) $row['updated_at'] : null,
        ];
    }

    /**
     * @param array{
     *   prompt_template?: string,
     *   image_enabled?: bool|int|string,
     *   image_model?: string,
     *   image_size?: string,
     *   image_prompt_template?: string
     * } $data
     */
    public function save(array $data): void
    {
        $defaults = $this->defaults();
        $row = $this->fetchPersistenceRow();
        $preserveKey = null;
        $preserveModel = 'gpt-4o-mini';
        if (is_array($row)) {
            $k = trim((string) ($row['openai_api_key'] ?? ''));
            $preserveKey = $k !== '' ? $k : null;
            $preserveModel = trim((string) ($row['openai_model'] ?? '')) ?: 'gpt-4o-mini';
        }

        $prompt = isset($data['prompt_template']) ? trim((string) $data['prompt_template']) : (is_array($row) ? trim((string) ($row['prompt_template'] ?? '')) : '');
        if ($prompt === '') {
            $prompt = $defaults['prompt_template'];
        }

        $imgEnabled = array_key_exists('image_enabled', $data)
            ? (!empty($data['image_enabled']) ? 1 : 0)
            : (int) (is_array($row) ? ($row['image_enabled'] ?? 0) : 0);

        $imgModel = isset($data['image_model']) ? trim((string) $data['image_model']) : (is_array($row) ? trim((string) ($row['image_model'] ?? '')) : '');
        $imgModel = $imgModel !== '' ? $imgModel : $defaults['image_model'];
        if (strlen($imgModel) > 80) {
            $imgModel = substr($imgModel, 0, 80);
        }

        $imgSize = isset($data['image_size']) ? trim((string) $data['image_size']) : (is_array($row) ? trim((string) ($row['image_size'] ?? '')) : '');
        $imgSize = $imgSize !== '' ? $imgSize : $defaults['image_size'];
        if (!in_array($imgSize, \AviosDestinationReviewPlugin\OpenAiImageClient::ALLOWED_SIZES, true)) {
            $imgSize = $defaults['image_size'];
        }

        $imgPrompt = isset($data['image_prompt_template']) ? trim((string) $data['image_prompt_template']) : (is_array($row) ? trim((string) ($row['image_prompt_template'] ?? '')) : '');
        if ($imgPrompt === '') {
            $imgPrompt = $defaults['image_prompt_template'];
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO adr_settings
                (id, openai_api_key, openai_model, prompt_template,
                 image_enabled, image_model, image_size, image_prompt_template)
             VALUES (1, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                openai_api_key = VALUES(openai_api_key),
                openai_model = VALUES(openai_model),
                prompt_template = VALUES(prompt_template),
                image_enabled = VALUES(image_enabled),
                image_model = VALUES(image_model),
                image_size = VALUES(image_size),
                image_prompt_template = VALUES(image_prompt_template)'
        );
        $stmt->execute([
            $preserveKey,
            $preserveModel,
            $prompt,
            $imgEnabled,
            $imgModel,
            $imgSize,
            $imgPrompt,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchPersistenceRow(): ?array
    {
        if (!$this->tableExists()) {
            return null;
        }
        $stmt = $this->pdo->query(
            'SELECT openai_api_key, openai_model, prompt_template, image_enabled, image_model, image_size, image_prompt_template
             FROM adr_settings WHERE id = 1 LIMIT 1'
        );
        $r = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($r) ? $r : null;
    }

    /**
     * @return AdrSettings
     */
    private function defaults(): array
    {
        return [
            'openai_api_key' => '',
            'openai_model' => OpenAiApiKeyResolver::activeModel(),
            'prompt_template' => $this->defaultPromptTemplate(),
            'image_enabled' => false,
            'image_model' => 'gpt-image-1',
            'image_size' => '1536x1024',
            'image_prompt_template' => $this->defaultImagePromptTemplate(),
            'api_key_stored' => OpenAiApiKeyResolver::resolve() !== '',
            'openai_env_override' => OpenAiApiKeyResolver::hasEnvApiKey(),
            'updated_at' => null,
        ];
    }

    private function defaultImagePromptTemplate(): string
    {
        return 'Editorial-style travel photograph of {{destination}}, captured during golden hour. '
            . 'Cinematic wide-angle shot, natural lighting, vibrant but realistic colours, sharp focus, '
            . 'no text, no watermarks, no people in the foreground. Showcase the most iconic landmark or '
            . 'skyline of {{destination}} (IATA {{iata}}). 16:9 aspect, photojournalistic feel, suitable '
            . 'as the hero image of a premium travel-rewards article.';
    }

    private function defaultPromptTemplate(): string
    {
        // Mirrors the seed in 001_adr_schema.sql so admins can "restore default" cleanly.
        return <<<'PROMPT'
You are a senior travel-rewards journalist writing for a UK Avios audience. Write a 600-800 word destination review for travellers redeeming British Airways Avios from London Heathrow (LHR) to {{destination}} ({{iata}}).

The review must be optimised for search intent around the keyword "Avios flights to {{destination}}" while reading naturally for humans. Avoid AI tells and clichés like "nestled in" or "hidden gem". Use UK English and a confident, practical tone.

Return a single JSON object with these keys:
- meta_title:        max 60 characters, includes both "{{destination}}" and "Avios"
- meta_description:  max 155 characters, action-oriented, includes "{{destination}}"
- content_html:      the article body as semantic HTML using <h2>, <h3>, <p>, <ul>, <li>. Do NOT include <html>, <head>, <body>, or an outer <h1>.

The content_html must include, in order:
1. A short 2-sentence intro answering "is {{destination}} worth visiting on Avios?".
2. <h2>Why redeem Avios to {{destination}}</h2> - value angle, peak vs off-peak considerations, cabin classes typically available on British Airways from Heathrow.
3. <h2>Best time to visit {{destination}}</h2> - months, weather, notable events.
4. <h2>How to get there with Avios</h2> - practical British Airways routing notes from LHR, typical Avios cost ranges by cabin (use plausible ranges, never invent exact figures), and tips on finding award availability.
5. <h2>What to do in {{destination}}</h2> - 4 to 6 concrete, well-known things to do or see.
6. <h2>Where to stay</h2> - 2 to 3 neighbourhood suggestions for points-rich hotel programmes (IHG One Rewards, Marriott Bonvoy, Hilton Honors) without inventing specific property names.
7. <h2>Final word</h2> - a 2-3 sentence verdict for an Avios collector deciding whether to book.

Hard rules:
- Do NOT invent specific Avios prices, exact hotel names, airline route numbers or tour operators.
- Use lists where natural; no more than two <ul> lists overall.
- When referring to "Avios calculator", "Nectar to Avios" or "credit cards", write the plain phrase (no <a> tags) so the CMS can link them later.
- No first-person ("I", "we") - write as the publication.
- No emojis. No markdown. Plain HTML only.
PROMPT;
    }
}
