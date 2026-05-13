<?php

declare(strict_types=1);

namespace AviosDestinationReviewPlugin;

use PDO;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Exposes destination-review data to Twig templates so themes can render homepage
 * widgets driven by the generated review corpus.
 *
 * Functions:
 *   - adr_best_redemptions(limit = 5) → list of {iata, destination, slug, image_url,
 *     avios, href} rows for the most-recent published destination reviews. The
 *     `avios` field is the *lowest* known Avios cost to that destination across
 *     any cabin or season in hma_fares (i.e. a "from X Avios" entry-point).
 *     Returns [] when the content type / fare table is missing or no reviews
 *     exist.
 *   - adr_index_cards(entryIds) → enriches the CMS public index_entries (which
 *     only carry generic fields) with destination-specific data (IATA + lowest
 *     Avios). Returns a map keyed by entry id: {entry_id: {iata, min_avios}}.
 *     Used by the travel-site /destinations index template — keeps the queries
 *     to exactly two regardless of page size (one for IATAs, one for min fares).
 */
final class AviosDestinationReviewTwigExtension extends AbstractExtension
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('adr_best_redemptions', $this->bestRedemptions(...)),
            new TwigFunction('adr_index_cards', $this->indexCards(...)),
        ];
    }

    /**
     * @return list<array{
     *   iata:string, destination:string, slug:string, image_url:string,
     *   avios:int, href:string
     * }>
     */
    public function bestRedemptions(int $limit = 5): array
    {
        $limit = max(1, min(20, $limit));

        // Pull the most-recent published destination reviews along with their IATA value
        // and (when present) the featured image path. Entries that have a hero image rank
        // first so the homepage layout never lands on a placeholder when we have better
        // candidates available.
        $sql = <<<SQL
            SELECT
              e.id, e.title, e.slug, e.published_at,
              m.path AS image_path,
              v.value_longtext AS iata
            FROM cms_content_entries e
            INNER JOIN cms_content_types  t ON t.id = e.content_type_id AND t.slug = 'destinations'
            INNER JOIN cms_content_fields f ON f.content_type_id = t.id AND f.field_key = 'iata'
            LEFT JOIN cms_content_entry_values v
                ON v.content_entry_id = e.id AND v.field_id = f.id
            LEFT JOIN cms_media m
                ON m.id = e.featured_image_id
            WHERE e.status = 'published'
              AND v.value_longtext IS NOT NULL
              AND v.value_longtext <> ''
            ORDER BY (m.path IS NOT NULL) DESC, e.published_at DESC
            LIMIT {$limit}
        SQL;

        try {
            $stmt = $this->pdo->query($sql);
            $entries = $stmt instanceof \PDOStatement ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        } catch (\PDOException) {
            return [];
        }

        if ($entries === []) {
            return [];
        }

        // Per-row "lowest fare" lookup — any cabin, any season. Gives the "from X Avios"
        // entry-point that frames the section as an approachable browse, rather than a
        // premium-cabin shortlist.
        try {
            $fareStmt = $this->pdo->prepare(
                'SELECT MIN(avios_amount) AS min_avios
                   FROM hma_fares
                  WHERE iata = ? AND avios_amount > 0'
            );
        } catch (\PDOException) {
            // hma_fares missing — show the entries without fare data rather than blanking
            // the whole section.
            $fareStmt = null;
        }

        $out = [];
        foreach ($entries as $row) {
            $iata = strtoupper(trim((string) ($row['iata'] ?? '')));
            $slug = (string) ($row['slug'] ?? '');
            if ($slug === '' || $iata === '') {
                continue;
            }

            $avios = 0;
            if ($fareStmt !== null) {
                try {
                    $fareStmt->execute([$iata]);
                    $v = $fareStmt->fetchColumn();
                    $avios = is_numeric($v) ? (int) $v : 0;
                } catch (\PDOException) {
                    // Leave 0; the template can fall back to "View destination →".
                }
            }

            $out[] = [
                'iata' => $iata,
                'destination' => (string) ($row['title'] ?? ''),
                'slug' => $slug,
                'image_url' => (string) ($row['image_path'] ?? ''),
                'avios' => $avios,
                'href' => '/destinations/' . $slug,
            ];
        }

        return $out;
    }

    /**
     * Batch-enrich CMS index entries with destination-specific fields. Two queries
     * total regardless of how many entries are passed:
     *   1. Pull each entry's IATA from `cms_content_entry_values` in one IN clause.
     *   2. Pull the lowest known Avios fare for each distinct IATA in a second IN clause.
     *
     * @param list<int|string> $entryIds Content entry IDs from `index_entries`.
     * @return array<int, array{iata:string, min_avios:int}> Keyed by entry id.
     */
    public function indexCards(array $entryIds): array
    {
        $entryIds = array_values(array_unique(array_filter(
            array_map(static fn ($v): int => (int) $v, $entryIds),
            static fn (int $id): bool => $id > 0,
        )));
        if ($entryIds === []) {
            return [];
        }

        // Resolve the IATA field id for the `destinations` content type once. Inlining
        // this with the values-table lookup would be a tight join but the field id is
        // effectively constant within a request, so a one-shot prepared lookup is
        // simpler and just as fast.
        try {
            $fieldStmt = $this->pdo->prepare(
                'SELECT cf.id
                   FROM cms_content_fields cf
                   INNER JOIN cms_content_types ct ON ct.id = cf.content_type_id
                  WHERE ct.slug = ? AND cf.field_key = ?
                  LIMIT 1'
            );
            $fieldStmt->execute(['destinations', 'iata']);
            $iataFieldId = (int) ($fieldStmt->fetchColumn() ?: 0);
        } catch (\PDOException) {
            return [];
        }

        if ($iataFieldId <= 0) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($entryIds), '?'));
        try {
            $valStmt = $this->pdo->prepare(
                "SELECT content_entry_id AS entry_id, value_longtext AS iata
                   FROM cms_content_entry_values
                  WHERE field_id = ?
                    AND content_entry_id IN ({$placeholders})
                    AND value_longtext IS NOT NULL"
            );
            $valStmt->execute(array_merge([$iataFieldId], $entryIds));
            $rows = $valStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\PDOException) {
            return [];
        }

        $byEntryId = [];
        $iatas = [];
        foreach ($rows as $r) {
            $eid = (int) ($r['entry_id'] ?? 0);
            $iata = strtoupper(trim((string) ($r['iata'] ?? '')));
            if ($eid <= 0 || $iata === '') {
                continue;
            }
            $byEntryId[$eid] = ['iata' => $iata, 'min_avios' => 0];
            $iatas[$iata] = true;
        }

        if ($iatas !== []) {
            $iataList = array_keys($iatas);
            $iataPlaceholders = implode(',', array_fill(0, count($iataList), '?'));
            try {
                $minStmt = $this->pdo->prepare(
                    "SELECT iata, MIN(avios_amount) AS min_avios
                       FROM hma_fares
                      WHERE avios_amount > 0
                        AND iata IN ({$iataPlaceholders})
                      GROUP BY iata"
                );
                $minStmt->execute($iataList);
                $minByIata = [];
                foreach ($minStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                    $minByIata[strtoupper((string) $r['iata'])] = (int) $r['min_avios'];
                }
                foreach ($byEntryId as $eid => $data) {
                    $byEntryId[$eid]['min_avios'] = $minByIata[$data['iata']] ?? 0;
                }
            } catch (\PDOException) {
                // hma_fares unavailable — leave min_avios at 0; template will fall back
                // to a "View destination" CTA without a price pill.
            }
        }

        return $byEntryId;
    }
}
