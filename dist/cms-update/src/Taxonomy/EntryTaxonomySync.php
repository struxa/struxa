<?php

declare(strict_types=1);

namespace App\Taxonomy;

/**
 * Persists taxonomy term links for a content entry.
 */
final class EntryTaxonomySync
{
    /**
     * @param list<int> $termIds
     */
    public static function sync(int $entryId, array $termIds, ContentEntryTaxonomyRepository $links): void
    {
        $links->replaceForEntry($entryId, $termIds);
    }
}
