<?php

declare(strict_types=1);

namespace App\Taxonomy;

/**
 * Term listing and lookups for admin and Twig.
 */
final class TaxonomyTermService
{
    public function __construct(
        private readonly TaxonomyTermRepository $terms,
    ) {
    }

    /**
     * @return list<TaxonomyTerm>
     */
    public function orderedForTaxonomy(int $taxonomyId): array
    {
        return $this->terms->forTaxonomyOrdered($taxonomyId);
    }

    public function find(int $id): ?TaxonomyTerm
    {
        return $this->terms->findById($id);
    }
}
