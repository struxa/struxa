<?php

declare(strict_types=1);

namespace AviosDestinationReviewPlugin;

use App\Ai\OpenAiApiKeyResolver;
use App\Ai\OpenAiChatClient;
use App\Ai\OpenAiException;

/**
 * Calls OpenAI with the admin-editable prompt template to produce a review JSON
 * object: { meta_title, meta_description, content_html }. The result is persisted as a
 * CMS content entry under the "destinations" content type; the plugin's adr_reviews
 * table is updated as a thin link/provenance row (iata -> entry_id, model, prompt).
 */
final class ReviewGenerator
{
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly ReviewRepository $reviews,
        private readonly ContentEntryService $contentEntries,
        private readonly ?ImageGenerator $images = null,
    ) {
    }

    /**
     * OpenAI API key from {@see OpenAiApiKeyResolver} only (environment
     * variables or the key saved at /admin/system/api-keys). The plugin
     * no longer stores its own duplicate key in adr_settings.
     */
    public function resolveApiKey(): string
    {
        return OpenAiApiKeyResolver::resolve();
    }

    /**
     * Chat completion model from {@see OpenAiApiKeyResolver::activeModel()}
     * (env override or cms_settings from the same system screen).
     */
    public function resolveModel(): string
    {
        return OpenAiApiKeyResolver::activeModel();
    }

    /**
     * Substitute the two template placeholders. Kept dumb on purpose so non-technical
     * editors can preview exactly what gets sent.
     */
    public function renderPrompt(string $destination, string $iata): string
    {
        $template = $this->settings->get()['prompt_template'];

        return strtr($template, [
            '{{destination}}' => $destination,
            '{{iata}}' => strtoupper($iata),
        ]);
    }

    /**
     * Generate and persist a review for one (iata, destination) pair.
     *
     * @return array{ok:bool, id:int, error?:string, review?:array<string,string>}
     */
    public function generateAndStore(string $iata, string $destination): array
    {
        $apiKey = $this->resolveApiKey();
        if ($apiKey === '') {
            return ['ok' => false, 'id' => 0, 'error' => 'No OpenAI API key configured. Add one under System → API keys (/admin/system/api-keys), or set OPENAI_API_KEY in the environment.'];
        }
        $model = $this->resolveModel();
        $prompt = $this->renderPrompt($destination, $iata);

        $client = new OpenAiChatClient(120.0);
        try {
            $raw = $client->chatJsonObject(
                $apiKey,
                $model,
                [
                    ['role' => 'system', 'content' => 'You output a single JSON object. No markdown, no commentary.'],
                    ['role' => 'user', 'content' => $prompt],
                ],
                0.55
            );
        } catch (OpenAiException $e) {
            return ['ok' => false, 'id' => 0, 'error' => 'OpenAI error: ' . $e->getMessage()];
        }

        /** @var mixed $decoded */
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'id' => 0, 'error' => 'OpenAI returned non-object JSON.'];
        }

        $metaTitle = $this->trimToLen((string) ($decoded['meta_title'] ?? ''), 160);
        $metaDesc  = $this->trimToLen((string) ($decoded['meta_description'] ?? ''), 255);
        $html      = (string) ($decoded['content_html'] ?? '');
        if (trim($html) === '') {
            return ['ok' => false, 'id' => 0, 'error' => 'OpenAI response missing content_html.'];
        }

        $html = $this->sanitize($html);

        if (!$this->contentEntries->isReady()) {
            return [
                'ok' => false,
                'id' => 0,
                'error' => 'Destinations content type not installed. Run plugin migration 002_adr_content_type.sql.',
            ];
        }

        // Plugin row first — it owns slug generation and provenance — then push the canonical
        // content into a cms_content_entries row keyed by the same slug.
        $linkId = $this->reviews->upsert($iata, $destination, $metaTitle, $metaDesc, $html, $model, $prompt);
        $slug = (string) ($this->reviews->findById($linkId)['slug'] ?? '');

        // Optional hero image. Stays best-effort — text generation already succeeded, so
        // any image failure is surfaced as a `warning` but does NOT fail the whole request.
        $imageWarning = '';
        $mediaId = null;
        $cfg = $this->settings->get();
        if ($this->images !== null && $cfg['image_enabled']) {
            $img = $this->images->generateAndStore($apiKey, $destination, $iata);
            if ($img['ok']) {
                $mediaId = $img['media_id'];
            } else {
                $imageWarning = $img['error'];
            }
        }

        $entryId = $this->contentEntries->upsertEntry($iata, $destination, $slug, [
            'meta_title' => $metaTitle,
            'meta_description' => $metaDesc,
            'content_html' => $html,
            'featured_image_id' => $mediaId,
        ]);
        $this->reviews->linkEntry($linkId, $entryId);

        return [
            'ok' => true,
            'id' => $linkId,
            'entry_id' => $entryId,
            'image_warning' => $imageWarning,
            'media_id' => $mediaId,
            'review' => [
                'meta_title' => $metaTitle,
                'meta_description' => $metaDesc,
                'content_html' => $html,
                'model_used' => $model,
                'slug' => $slug,
                'featured_image_id' => $mediaId,
            ],
        ];
    }

    private function trimToLen(string $v, int $max): string
    {
        $v = trim($v);
        if ($v === '') {
            return '';
        }

        return mb_substr($v, 0, $max);
    }

    /**
     * Strip dangerous tags from model output. We're conservative: drop <script>, <style>,
     * <iframe>, on* event attributes, and javascript: hrefs. The editor can re-add anything later.
     */
    private function sanitize(string $html): string
    {
        $html = preg_replace('#<(script|style|iframe)\b[^>]*>.*?</\1>#is', '', $html) ?? $html;
        $html = preg_replace('#\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|\S+)#i', '', $html) ?? $html;
        $html = preg_replace('#(href|src)\s*=\s*(["\'])\s*javascript:[^"\']*\2#i', '$1=$2#$2', $html) ?? $html;

        return $html;
    }
}
