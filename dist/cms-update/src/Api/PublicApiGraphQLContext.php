<?php

declare(strict_types=1);

namespace App\Api;

use App\Content\ContentEntryRepository;
use App\Content\ContentEntryValueRepository;
use App\Content\ContentFieldRepository;
use App\Content\ContentTypeRepository;
use App\Media\MediaUrlHelper;
use App\Page\PageRepository;
use App\Section\PageSectionRepository;
use App\Section\SectionRenderer;
use App\Taxonomy\ContentEntryTaxonomyRepository;
use Twig\Environment;

/**
 * Shared dependencies for GraphQL resolvers (read-only, mirrors REST rules).
 */
final class PublicApiGraphQLContext
{
    public function __construct(
        public readonly PublicApiAuthContext $auth,
        public readonly string $siteUrl,
        public readonly \PDO $pdo,
        public readonly ContentTypeRepository $types,
        public readonly ContentFieldRepository $fields,
        public readonly ContentEntryRepository $entries,
        public readonly ContentEntryValueRepository $values,
        public readonly MediaUrlHelper $mediaUrls,
        public readonly ContentEntryTaxonomyRepository $entryTaxonomies,
        public readonly PageRepository $pages,
        public readonly Environment $twig,
        public readonly PageSectionRepository $pageSections,
        public readonly SectionRenderer $sectionRenderer,
    ) {
    }
}
