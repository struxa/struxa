<?php

declare(strict_types=1);

namespace App\Ai;

use App\Content\ContentEntryRepository;
use App\Page\PageRepository;
use PDO;

/**
 * Lists real published storefront URLs for injection into AI draft prompts (accurate internal links).
 */
final class AiDraftPublishedPagesContext
{
    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    /**
     * Markdown-style block for the user message; empty string if nothing is published.
     */
    public function buildSection(int $prioritizeContentTypeId): string
    {
        $entries = (new ContentEntryRepository($this->pdo))->listPublishedPublicPathsForSiteContext(
            $prioritizeContentTypeId,
            72
        );
        $pages = (new PageRepository($this->pdo))->listPublishedPathsForSiteContext(24);
        if ($entries === [] && $pages === []) {
            return '';
        }

        $lines = [
            '### Published pages on this site (root-relative paths only)',
            'Site home: /',
            '',
        ];
        if ($pages !== []) {
            $lines[] = 'CMS pages (`/p/...`):';
            foreach ($pages as $p) {
                $lines[] = '- ' . $p['path'] . ' — ' . $this->oneLineForPrompt($p['title']);
            }
            $lines[] = '';
        }
        if ($entries !== []) {
            $lines[] = 'Content entries (public types):';
            foreach ($entries as $e) {
                $lines[] = '- ' . $e['path'] . ' — ' . $this->oneLineForPrompt($e['title'])
                    . ' (' . $this->oneLineForPrompt($e['type_name']) . ')';
            }
        }
        $lines[] = '';
        $lines[] = 'When adding internal links in rich text, use <a href="/path"> with href values exactly from the lists above or href="/" for home. Do not guess URLs.';

        return implode("\n", $lines);
    }

    private function oneLineForPrompt(string $s): string
    {
        $s = preg_replace('/\s+/u', ' ', trim($s)) ?? '';
        if (mb_strlen($s) > 100) {
            return mb_substr($s, 0, 97) . '…';
        }

        return $s;
    }
}
