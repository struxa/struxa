<?php

declare(strict_types=1);

namespace App\Page;

use App\Section\PageSectionRepository;

final class PageDuplicationService
{
    public function __construct(
        private readonly PageRepository $pages,
        private readonly PageSectionRepository $sections,
    ) {
    }

    public function duplicate(int $sourceId): ?int
    {
        $page = $this->pages->findById($sourceId);
        if ($page === null) {
            return null;
        }

        $title = self::copyTitle($page->title);
        $slug = PageSlugger::ensureUnique($this->pages, PageSlugger::slugify($title), null);
        $tagsJson = PageTagParser::toJson($page->tags);

        $newId = $this->pages->insert(
            $title,
            $slug,
            $page->seoTitle,
            $page->seoDescription,
            $page->focusKeyphrase,
            $tagsJson,
            $page->featuredImageId,
            null,
            $page->seoNoindex,
            $page->ogTitle,
            $page->ogDescription,
            $page->ogImageId,
            $page->twitterTitle,
            $page->twitterDescription,
            $page->twitterImageId,
            $page->schemaJson,
            $page->content,
            'draft',
            null,
            null,
            $page->commentsDisabled,
        );

        $blocks = $this->sections->exportBlocksForPage($sourceId);
        if ($blocks !== []) {
            $this->sections->replaceAllForPage($newId, $blocks);
        }

        return $newId;
    }

    public static function copyTitle(string $title): string
    {
        $title = trim($title);

        return $title === '' ? 'Untitled (Copy)' : $title . ' (Copy)';
    }
}
