<?php

declare(strict_types=1);

namespace App\Twig;

use App\Content\ContentEntryRepository;
use App\Content\ContentTypeRepository;
use App\Content\PublicContentIndexCardBuilder;
use App\Content\ReservedContentSlugs;
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
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('content_type_cards', $this->contentTypeCards(...)),
            new TwigFunction('content_type_cards_journal', $this->contentTypeCardsJournal(...)),
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
}
