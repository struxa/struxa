<?php

declare(strict_types=1);

namespace App\Content;

use App\Page\PageDuplicationService;
use App\Section\ContentEntrySectionRepository;
use App\Taxonomy\ContentEntryTaxonomyRepository;
use App\Taxonomy\EntryTaxonomySync;

final class ContentEntryDuplicationService
{
    public function __construct(
        private readonly ContentEntryRepository $entries,
        private readonly ContentEntryValueRepository $values,
        private readonly ContentEntrySectionRepository $sections,
        private readonly ContentEntryTaxonomyRepository $entryTaxonomy,
    ) {
    }

    public function duplicate(int $sourceEntryId, ?int $createdBy = null): ?int
    {
        $entry = $this->entries->findById($sourceEntryId);
        if ($entry === null) {
            return null;
        }

        $title = PageDuplicationService::copyTitle($entry->title);
        $slug = ContentSlugger::ensureUniqueEntry(
            $this->entries,
            $entry->contentTypeId,
            ContentSlugger::slugify($title),
            null,
        );

        $newId = $this->entries->insert(
            $entry->contentTypeId,
            $title,
            $slug,
            'draft',
            $entry->featuredImageId,
            $entry->seoTitle,
            $entry->seoDescription,
            $entry->focusKeyphrase,
            null,
            $entry->seoNoindex,
            $entry->ogTitle,
            $entry->ogDescription,
            $entry->ogImageId,
            $entry->twitterTitle,
            $entry->twitterDescription,
            $entry->twitterImageId,
            $entry->schemaJson,
            null,
            null,
            null,
            $createdBy,
        );

        foreach ($this->values->valuesByFieldIdForEntry($sourceEntryId) as $fieldId => $value) {
            $this->values->upsert($newId, $fieldId, $value !== '' ? $value : null);
        }

        $termIds = $this->entryTaxonomy->termIdsForEntry($sourceEntryId);
        if ($termIds !== []) {
            EntryTaxonomySync::sync($newId, $termIds, $this->entryTaxonomy);
        }

        $blocks = $this->sections->exportBlocksForEntry($sourceEntryId);
        if ($blocks !== []) {
            $this->sections->replaceAllForEntry($newId, $blocks);
        }

        return $newId;
    }
}
