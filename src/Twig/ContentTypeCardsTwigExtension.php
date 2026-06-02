<?php

declare(strict_types=1);

namespace App\Twig;

use App\Content\ContentEntryRepository;
use App\Content\ContentEntryValueRepository;
use App\Content\ContentFieldRepository;
use App\Content\ContentTypeRepository;
use App\Content\PublicContentIndexCardBuilder;
use App\Content\ReservedContentSlugs;
use App\Media\MediaUrlHelper;
use PDO;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Exposes published content-type entries for CMS section templates (e.g. homepage product grid).
 */
final class ContentTypeCardsTwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly ContentTypeRepository $types,
        private readonly ContentEntryRepository $entries,
        private readonly PublicContentIndexCardBuilder $cardBuilder,
        private readonly PDO $pdo,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('content_type_cards', $this->contentTypeCards(...)),
            new TwigFunction('content_type_cards_journal', $this->contentTypeCardsJournal(...)),
            new TwigFunction('content_type_entry', $this->contentTypeEntry(...)),
        ];
    }

    /**
     * Picks the best public “journal” type for homepage carousels: preferred slugs first, then any
     * non-catalog public type with published entries. Falls back to an empty preferred type for UI hints.
     *
     * @return array{type: \App\Content\ContentType|null, entries: list<array<string, mixed>>}
     */
    public function contentTypeCardsJournal(string|int $limit = 12): array
    {
        $n = is_int($limit) ? $limit : (int) preg_replace('/\D+/', '', (string) $limit);
        if ($n < 1) {
            $n = 12;
        }
        $limit = max(1, min(24, $n));

        $catalogSlugs = ['products', 'store', 'catalog', 'shop'];
        $preferred = ['blog', 'news', 'journal', 'articles', 'posts', 'post', 'reviews', 'review', 'updates'];

        foreach ($preferred as $slug) {
            $pack = $this->contentTypeCards($slug, $limit);
            if ($pack['type'] !== null && $pack['entries'] !== []) {
                return $pack;
            }
        }

        $bestId = $this->entries->firstPublicNonCatalogTypeIdWithPublishedEntries();
        if ($bestId !== null) {
            $type = $this->types->findById($bestId);
            if ($type !== null && $type->hasPublicRoute) {
                $rows = $this->entries->publishedForContentTypePaged($type->id, 1, $limit);
                if ($rows !== []) {
                    return [
                        'type' => $type,
                        'entries' => $this->cardBuilder->buildForEntries($type, $rows),
                    ];
                }
            }
        }

        foreach ($this->types->allOrdered() as $type) {
            if (!$type->hasPublicRoute || in_array(strtolower($type->slug), $catalogSlugs, true)) {
                continue;
            }
            $rows = $this->entries->publishedForContentTypePaged($type->id, 1, $limit);
            if ($rows !== []) {
                return [
                    'type' => $type,
                    'entries' => $this->cardBuilder->buildForEntries($type, $rows),
                ];
            }
        }

        foreach ($preferred as $slug) {
            $pack = $this->contentTypeCards($slug, $limit);
            if ($pack['type'] !== null) {
                return $pack;
            }
        }

        foreach ($this->types->allOrdered() as $type) {
            if (!$type->hasPublicRoute || in_array(strtolower($type->slug), $catalogSlugs, true)) {
                continue;
            }

            return ['type' => $type, 'entries' => []];
        }

        return ['type' => null, 'entries' => []];
    }

    /**
     * @return array{type: \App\Content\ContentType|null, entries: list<array<string, mixed>>}
     */
    public function contentTypeCards(string $typeSlug, string|int $limit = 6): array
    {
        $typeSlug = trim($typeSlug);
        if ($typeSlug === '' || ReservedContentSlugs::isReserved($typeSlug)) {
            return ['type' => null, 'entries' => []];
        }
        $type = $this->types->findBySlug($typeSlug) ?? $this->types->findBySlugCaseInsensitive($typeSlug);
        if ($type === null || !$type->hasPublicRoute) {
            return ['type' => null, 'entries' => []];
        }
        $n = is_int($limit) ? $limit : (int) preg_replace('/\D+/', '', (string) $limit);
        if ($n < 1) {
            $n = 6;
        }
        $limit = max(1, min(24, $n));
        $rows = $this->entries->publishedForContentTypePaged($type->id, 1, $limit);

        return [
            'type' => $type,
            'entries' => $this->cardBuilder->buildForEntries($type, $rows),
        ];
    }

    /**
     * Load one published entry (and field map) for homepage/marketing sections.
     * Does not require the content type to have a public route.
     *
     * @return array{
     *   type: \App\Content\ContentType|null,
     *   entry: \App\Content\ContentEntry|null,
     *   fields: array<string, string>,
     *   featured_url: string
     * }
     */
    public function contentTypeEntry(string $typeSlug, string $entrySlug = ''): array
    {
        $empty = ['type' => null, 'entry' => null, 'fields' => [], 'featured_url' => ''];
        $typeSlug = trim($typeSlug);
        if ($typeSlug === '' || ReservedContentSlugs::isReserved($typeSlug)) {
            return $empty;
        }
        $type = $this->types->findBySlug($typeSlug) ?? $this->types->findBySlugCaseInsensitive($typeSlug);
        if ($type === null) {
            return $empty;
        }

        $entrySlug = trim($entrySlug);
        $entry = $entrySlug !== ''
            ? $this->entries->findPublishedByTypeSlug($type->id, $entrySlug)
            : null;
        if ($entry === null) {
            $rows = $this->entries->publishedForContentTypePaged($type->id, 1, 1);
            if ($rows !== []) {
                $entry = \App\Content\ContentEntry::fromRow($rows[0]);
            }
        }
        if ($entry === null) {
            return ['type' => $type, 'entry' => null, 'fields' => [], 'featured_url' => ''];
        }

        $fields = new ContentFieldRepository($this->pdo);
        $values = new ContentEntryValueRepository($this->pdo);
        $mediaUrls = new MediaUrlHelper($this->pdo);

        $fieldList = $fields->forTypeOrdered($type->id);
        $valueMap = $values->valuesByFieldIdForEntry($entry->id);
        $byKey = [];
        foreach ($fieldList as $field) {
            $byKey[$field->fieldKey] = (string) ($valueMap[$field->id] ?? '');
        }

        $featuredUrl = '';
        if ($entry->featuredImageId !== null) {
            $featuredUrl = $mediaUrls->pathForId($entry->featuredImageId);
        }

        return [
            'type' => $type,
            'entry' => $entry,
            'fields' => $byKey,
            'featured_url' => $featuredUrl,
        ];
    }
}
