<?php

declare(strict_types=1);

namespace App\Ai;

use App\Content\ContentField;
use App\Content\ContentType;

/**
 * Calls OpenAI and builds a POST-like body for {@see \App\Content\ContentEntryFormValidator}.
 */
final class AiContentDraftService
{
    public function __construct(
        private readonly OpenAiChatClient $client = new OpenAiChatClient()
    ) {
    }

    /**
     * @param list<ContentField> $fields
     * @return array<string, mixed>
     */
    public function buildDraftBody(
        string $apiKey,
        string $model,
        ContentType $type,
        array $fields,
        string $brief,
        string $tone,
        string $sitePagesCatalog = '',
    ): array {
        $brief = trim($brief);
        if ($brief === '') {
            throw new \InvalidArgumentException('Brief is required.');
        }

        $tone = trim($tone) !== '' ? trim($tone) : 'clear, professional';
        $sitePagesCatalog = trim($sitePagesCatalog);

        $fieldLines = $this->fieldDescriptionsForPrompt($fields);
        if ($fieldLines === '') {
            throw new \InvalidArgumentException('This content type has no text fields the model can fill. Add at least one text, textarea, or rich text field.');
        }

        $seoHint = $type->supportsSeo
            ? 'Include seo_title (max 255 chars) and seo_description (max 500 chars) when relevant.'
            : 'Do not include seo_title or seo_description.';

        $linkCatalogHint = $sitePagesCatalog !== ''
            ? "\n\nThe user message may include a \"Published pages on this site\" section listing real internal paths. For links in rich text, use only <a href=\"/...\"> targets from that catalog or href=\"/\" for the site home. Do not invent site URLs."
            : '';

        $system = <<<SYS
You write structured content for a CMS. Reply with a single JSON object only (no markdown fence).
Keys:
- title (string, required): entry title.
- slug (string, optional): URL slug, lowercase letters, numbers, hyphens only; max ~180 chars. Omit to derive from title.
{$seoHint}
- custom_fields (object, required): keys are FIELD IDs as strings (digits). Values are strings.
For richtext fields use safe HTML: p, br, strong, em, a[href], ul, ol, li, h2, h3, h4, blockquote, code (no script, no inline styles).
Use h2+ for headings, never h1. For empty optional fields you may omit the key or use an empty string.{$linkCatalogHint}
SYS;

        $user = "Content type name: {$type->name}\n"
            . "Content type slug: {$type->slug}\n"
            . "Tone: {$tone}\n"
            . "Topic / brief:\n{$brief}\n\n"
            . "Fields to fill:\n{$fieldLines}\n";
        if ($sitePagesCatalog !== '') {
            $user .= "\n\n" . $sitePagesCatalog;
        }

        $content = $this->client->chatJsonObject($apiKey, $model, [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ]);

        /** @var mixed $data */
        $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \RuntimeException('Model returned non-object JSON.');
        }

        $title = isset($data['title']) && is_string($data['title']) ? trim($data['title']) : '';
        if ($title === '') {
            throw new \RuntimeException('Model JSON missing a non-empty title.');
        }

        $body = [
            'title' => $title,
            'slug' => isset($data['slug']) && is_string($data['slug']) ? trim($data['slug']) : '',
            'status' => 'draft',
            'custom_fields' => [],
        ];

        if ($type->supportsSeo) {
            $body['seo_title'] = isset($data['seo_title']) && is_string($data['seo_title']) ? trim($data['seo_title']) : '';
            $body['seo_description'] = isset($data['seo_description']) && is_string($data['seo_description'])
                ? trim($data['seo_description'])
                : '';
        }

        $cf = $data['custom_fields'] ?? null;
        if (!is_array($cf)) {
            $cf = [];
        }

        $outCustom = [];
        foreach ($cf as $k => $val) {
            $id = is_int($k) ? $k : (ctype_digit((string) $k) ? (int) $k : 0);
            if ($id < 1) {
                continue;
            }
            if ($val === null) {
                continue;
            }
            $outCustom[$id] = is_scalar($val) ? (string) $val : '';
        }
        $body['custom_fields'] = $outCustom;

        return $body;
    }

    /**
     * @param list<ContentField> $fields
     */
    private function fieldDescriptionsForPrompt(array $fields): string
    {
        $lines = [];
        foreach ($fields as $f) {
            $desc = $this->oneFieldLine($f);
            if ($desc !== null) {
                $lines[] = $desc;
            }
        }

        return implode("\n", $lines);
    }

    private function oneFieldLine(ContentField $f): ?string
    {
        $req = $f->isRequired ? 'required' : 'optional';
        $help = $f->helpText !== null && trim($f->helpText) !== '' ? ' Notes: ' . trim($f->helpText) : '';

        return match ($f->fieldType) {
            'richtext' => "ID {$f->id} — rich text (HTML body, {$req}) — label: {$f->label}{$help}",
            'textarea' => "ID {$f->id} — multiline plain text ({$req}) — label: {$f->label}{$help}",
            'text' => "ID {$f->id} — short text ({$req}) — label: {$f->label}{$help}",
            'url' => "ID {$f->id} — URL ({$req}) — label: {$f->label}{$help}",
            'number' => "ID {$f->id} — number as string ({$req}) — label: {$f->label}{$help}",
            'boolean' => "ID {$f->id} — boolean as \"1\" or \"0\" ({$req}) — label: {$f->label}{$help}",
            'date' => "ID {$f->id} — date as YYYY-MM-DD ({$req}) — label: {$f->label}{$help}",
            'select' => $this->selectLine($f, $req, $help),
            'entry_refs' => "ID {$f->id} — linked entries as JSON array of numeric entry IDs, e.g. [12,34] ({$req}) — label: {$f->label}{$help}",
            default => null,
        };
    }

    private function selectLine(ContentField $f, string $req, string $help): ?string
    {
        $opts = $f->selectOptions();
        if ($opts === []) {
            return null;
        }
        $allowed = array_map(static fn (array $o): string => (string) $o['value'], $opts);
        $list = implode(', ', array_slice($allowed, 0, 40));
        if (count($allowed) > 40) {
            $list .= ', …';
        }

        return "ID {$f->id} — select ({$req}); value MUST be exactly one of: {$list} — label: {$f->label}{$help}";
    }
}
