<?php

declare(strict_types=1);

namespace App\Taxonomy;

/**
 * Thin coordinator for taxonomy operations (entry side + archive reads).
 */
final class TaxonomyService
{
    public function __construct(
        private readonly TaxonomyRepository $taxonomies,
        private readonly ContentEntryTaxonomyRepository $entryLinks,
    ) {
    }

    /**
     * @return list<Taxonomy>
     */
    public function forContentType(int $contentTypeId): array
    {
        return $this->taxonomies->forContentTypeOrdered($contentTypeId);
    }

    /**
     * @return list<array{taxonomy: Taxonomy, terms: list<TaxonomyTerm>}>
     */
    public function termsGroupedForEntry(int $entryId): array
    {
        return $this->entryLinks->termsGroupedForEntry($entryId);
    }
}
