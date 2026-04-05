<?php

declare(strict_types=1);

namespace App\Twig;

use App\Taxonomy\ContentEntryTaxonomyRepository;
use App\Taxonomy\TaxonomyRepository;
use App\Taxonomy\TaxonomyTermRepository;
use Twig\Environment;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class TaxonomyTwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly ContentEntryTaxonomyRepository $entryTaxonomy,
        private readonly TaxonomyRepository $taxonomies,
        private readonly TaxonomyTermRepository $terms,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('entry_taxonomy_groups', $this->entryTaxonomyGroups(...)),
            new TwigFunction('taxonomy_archive_url', $this->taxonomyArchiveUrl(...), ['needs_environment' => true]),
            new TwigFunction('taxonomy_terms_for_type', $this->termsForType(...)),
        ];
    }

    /**
     * @return list<array{taxonomy: \App\Taxonomy\Taxonomy, terms: list<\App\Taxonomy\TaxonomyTerm>}>
     */
    private function entryTaxonomyGroups(int $entryId): array
    {
        if ($entryId < 1) {
            return [];
        }

        return $this->entryTaxonomy->termsGroupedForEntry($entryId);
    }

    private function taxonomyArchiveUrl(Environment $env, string $typeSlug, string $taxonomySlug, string $termSlug, int $page = 1): string
    {
        $globals = $env->getGlobals();
        $base = rtrim((string) ($globals['site_url'] ?? ''), '/');
        $typeSlug = trim($typeSlug, '/');
        $taxonomySlug = trim($taxonomySlug, '/');
        $termSlug = trim($termSlug, '/');
        if ($typeSlug === '' || $taxonomySlug === '' || $termSlug === '') {
            return $base;
        }
        $url = $base . '/' . rawurlencode($typeSlug) . '/' . rawurlencode($taxonomySlug) . '/' . rawurlencode($termSlug);
        if ($page > 1) {
            $url .= '?page=' . $page;
        }

        return $url;
    }

    /**
     * All terms for every taxonomy on a content type (for sidebars / filters).
     *
     * @return list<array{taxonomy: \App\Taxonomy\Taxonomy, terms: list<\App\Taxonomy\TaxonomyTerm>}>
     */
    private function termsForType(int $contentTypeId): array
    {
        if ($contentTypeId < 1) {
            return [];
        }
        $out = [];
        foreach ($this->taxonomies->forContentTypeOrdered($contentTypeId) as $tax) {
            $out[] = [
                'taxonomy' => $tax,
                'terms' => $this->terms->forTaxonomyOrdered($tax->id),
            ];
        }

        return $out;
    }
}
