<?php

declare(strict_types=1);

namespace App\Revisions;

use App\Page\Page;
use App\Page\PageRevisionRepository;
use App\Page\PageTagParser;
use App\Support\LineDiff;

/**
 * Human-readable revision diff for static pages.
 */
final class PageRevisionCompare
{
    /**
     * @param array<string, mixed> $leftRev revision row
     * @param array<string, mixed>|null $rightRev revision row or null when comparing to current
     * @param Page|null $currentPage when rightRev is null
     * @return array{
     *     metadata_changes: list<array{label: string, left: string, right: string, changed: bool}>,
     *     body_diff: string,
     *     sections: array{left: ?int, right: ?int, changed: bool},
     *     change_count: int,
     *     has_changes: bool
     * }
     */
    public function compare(array $leftRev, ?array $rightRev, ?Page $currentPage, ?int $currentSectionCount = null): array
    {
        $comparingToCurrent = $rightRev === null;
        $rightRev = $rightRev ?? $this->currentAsRevisionRow($currentPage);

        $metadata = $this->metadataChanges($leftRev, $rightRev);
        $leftContent = (string) ($leftRev['content'] ?? '');
        $rightContent = (string) ($rightRev['content'] ?? '');
        $bodyDiff = implode("\n", LineDiff::unified($leftContent, $rightContent));

        $leftSections = $this->sectionCount($leftRev);
        $rightSections = $comparingToCurrent && $currentSectionCount !== null
            ? $currentSectionCount
            : $this->sectionCount($rightRev);
        $sections = [
            'left' => $leftSections,
            'right' => $rightSections,
            'changed' => $leftSections !== $rightSections,
        ];

        $changeCount = count(array_filter($metadata, static fn (array $r): bool => $r['changed']))
            + ($sections['changed'] ? 1 : 0)
            + ($bodyDiff !== '  (no line differences)' ? 1 : 0);

        return [
            'metadata_changes' => $metadata,
            'body_diff' => $bodyDiff,
            'sections' => $sections,
            'change_count' => $changeCount,
            'has_changes' => $changeCount > 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function currentAsRevisionRow(?Page $page): array
    {
        if ($page === null) {
            return [];
        }

        return [
            'title' => $page->title,
            'slug' => $page->slug,
            'status' => $page->status,
            'seo_title' => $page->seoTitle,
            'seo_description' => $page->seoDescription,
            'tags_json' => PageTagParser::toJson($page->tags),
            'content' => $page->content,
            'sections_json' => null,
        ];
    }

    /**
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     * @return list<array{label: string, left: string, right: string, changed: bool}>
     */
    private function metadataChanges(array $left, array $right): array
    {
        $defs = [
            ['label' => 'Title', 'key' => 'title'],
            ['label' => 'URL slug', 'key' => 'slug'],
            ['label' => 'Status', 'key' => 'status'],
            ['label' => 'SEO title', 'key' => 'seo_title'],
            ['label' => 'SEO description', 'key' => 'seo_description'],
        ];
        $out = [];
        foreach ($defs as $def) {
            $key = $def['key'];
            $ls = $this->scalar($left[$key] ?? null);
            $rs = $this->scalar($right[$key] ?? null);
            $out[] = [
                'label' => $def['label'],
                'left' => $ls,
                'right' => $rs,
                'changed' => $ls !== $rs,
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $rev
     */
    private function sectionCount(array $rev): int
    {
        $n = PageRevisionRepository::sectionCountFromJson(
            isset($rev['sections_json']) ? (string) $rev['sections_json'] : null
        );

        return $n ?? 0;
    }

    private function scalar(mixed $v): string
    {
        if ($v === null || $v === '') {
            return '(empty)';
        }

        $s = trim(preg_replace('/\s+/u', ' ', (string) $v) ?? '');
        if (mb_strlen($s) > 160) {
            return mb_substr($s, 0, 157) . '…';
        }

        return $s;
    }
}
