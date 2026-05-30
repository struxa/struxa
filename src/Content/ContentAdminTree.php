<?php

declare(strict_types=1);

namespace App\Content;

/**
 * Sidebar tree data for admin content-type navigation.
 *
 * @return list<array{
 *   type: object,
 *   entry_total: int,
 *   entry_published: int,
 *   entry_draft: int,
 *   entry_in_review: int,
 *   field_count: int
 * }>
 */
final class ContentAdminTree
{
    public static function cards(
        ContentTypeRepository $types,
        ContentEntryRepository $entries,
        ContentFieldRepository $fields,
    ): array {
        $typeRows = $types->allOrdered();
        $entryStats = $entries->statsByContentType();
        $fieldCounts = $fields->countsByContentType();
        $cards = [];
        foreach ($typeRows as $t) {
            $stats = $entryStats[$t->id] ?? ['total' => 0, 'published' => 0, 'draft' => 0, 'in_review' => 0];
            $cards[] = [
                'type' => $t,
                'entry_total' => (int) $stats['total'],
                'entry_published' => (int) $stats['published'],
                'entry_draft' => (int) $stats['draft'],
                'entry_in_review' => (int) $stats['in_review'],
                'field_count' => $fieldCounts[$t->id] ?? 0,
            ];
        }

        return $cards;
    }

    /**
     * @return array{types: int, entries: int, published: int, draft: int}
     */
    public static function summary(array $cards): array
    {
        $summary = ['types' => count($cards), 'entries' => 0, 'published' => 0, 'draft' => 0];
        foreach ($cards as $card) {
            $summary['entries'] += $card['entry_total'];
            $summary['published'] += $card['entry_published'];
            $summary['draft'] += $card['entry_draft'] + $card['entry_in_review'];
        }

        return $summary;
    }
}
