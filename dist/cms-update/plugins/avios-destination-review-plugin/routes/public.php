<?php

declare(strict_types=1);

use App\Content\ContentTypeRepository;
use App\Plugin\PluginBootContext;
use AviosDestinationReviewPlugin\AviosDestinationReviewTwigExtension;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;

return function (App $app, PluginBootContext $ctx): void {
    $twig = $ctx->twig();
    $pdo = $ctx->pdo();

    /**
     * GET /destinations
     *
     * Bespoke listing route that supersedes the generic CMS content-type index
     * for this content type. The CMS route paginates 6-at-a-time which is a
     * poor fit for a destination browser — users want to filter by Avios cost
     * and see the whole catalogue at once.
     *
     * This route loads every published destination in one shot (~100 rows is
     * fine to ship to the client; per-card images are lazy-loaded), enriches
     * each with the lowest known fare from `hma_fares`, and hands the same
     * `index_entries` shape the existing template already understands. The
     * template then renders all rows up-front and filters them client-side via
     * the pill UI + search box.
     *
     * Registers before the CMS catch-all `/{typeSlug}` route so it takes
     * precedence when this plugin is active; if the plugin is disabled the
     * generic CMS route still serves a (basic) /destinations listing as a
     * graceful fallback.
     */
    $app->get('/destinations', function (Request $request, Response $response) use ($ctx, $twig, $pdo): Response {
        $types = new ContentTypeRepository($pdo);
        $type = $types->findBySlug('destinations');
        if ($type === null || !$type->hasPublicRoute) {
            // Content type not registered (e.g. migrations haven't been applied
            // to a fresh checkout) — fall back to a 404 rather than rendering
            // an empty grid that looks broken.
            $response->getBody()->write('Destinations content type not configured.');
            return $response->withStatus(404);
        }

        // One query for the entry list + IATA + image path. Pulls everything
        // we need to build cards without N+1 lookups.
        $entries = [];
        try {
            $sql = <<<SQL
                SELECT
                  e.id,
                  e.title,
                  e.slug,
                  e.published_at,
                  e.featured_image_id,
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
                ORDER BY e.title ASC
            SQL;
            $stmt = $pdo->query($sql);
            $entries = $stmt instanceof \PDOStatement ? ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []) : [];
        } catch (\PDOException) {
            $entries = [];
        }

        // Second batched query: lowest Avios fare per cabin per IATA. We pull
        // every (cabin, iata) row in one go and pivot to a per-IATA hash so
        // each card's enrichment is an O(1) lookup. The aggregate "any cabin"
        // minimum is derived from the same data — no extra trip needed.
        //
        // Cabin labels mirror the `cabin` enum in `hma_fares`. We also keep a
        // map of slug → label so the template / client can deal in URL-safe
        // identifiers without worrying about the space in "Premium Economy".
        $cabinLabels = [
            'economy' => 'Economy',
            'premium' => 'Premium Economy',
            'business' => 'Business',
            'first' => 'First',
        ];
        $cabinByLabel = array_flip($cabinLabels);

        /** @var array<string, int> */
        $minByIata = [];
        /** @var array<string, array<string, int>> iata → cabin slug → min avios */
        $minByIataCabin = [];

        $iatas = array_values(array_unique(array_filter(array_map(
            static fn (array $r): string => strtoupper(trim((string) ($r['iata'] ?? ''))),
            $entries,
        ))));
        if ($iatas !== []) {
            try {
                $ph = implode(',', array_fill(0, count($iatas), '?'));
                $stmt = $pdo->prepare(
                    "SELECT iata, cabin, MIN(avios_amount) AS min_avios
                       FROM hma_fares
                      WHERE avios_amount > 0 AND iata IN ({$ph})
                      GROUP BY iata, cabin"
                );
                $stmt->execute($iatas);
                foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $r) {
                    $iata = strtoupper((string) $r['iata']);
                    $label = (string) $r['cabin'];
                    $slug = $cabinByLabel[$label] ?? null;
                    $val = (int) $r['min_avios'];
                    if ($slug === null || $val <= 0) {
                        continue;
                    }
                    $minByIataCabin[$iata][$slug] = $val;
                    if (!isset($minByIata[$iata]) || $val < $minByIata[$iata]) {
                        $minByIata[$iata] = $val;
                    }
                }
            } catch (\PDOException) {
                // hma_fares missing — leave $minByIata empty; cards will still
                // render, just without the "From X Avios" pill.
            }
        }

        // Third batched query: excerpts via the standard `excerpt` field (if it
        // exists for this content type). One IN-clause read; skipped entirely
        // if the field isn't defined yet.
        $excerptByEntryId = [];
        try {
            $excerptFieldStmt = $pdo->prepare(
                "SELECT id FROM cms_content_fields
                  WHERE content_type_id = ? AND field_key = 'excerpt' LIMIT 1"
            );
            $excerptFieldStmt->execute([$type->id]);
            $excerptFieldId = (int) ($excerptFieldStmt->fetchColumn() ?: 0);
        } catch (\PDOException) {
            $excerptFieldId = 0;
        }
        if ($excerptFieldId > 0 && $entries !== []) {
            $entryIds = array_map(static fn (array $r): int => (int) $r['id'], $entries);
            $ph = implode(',', array_fill(0, count($entryIds), '?'));
            try {
                $stmt = $pdo->prepare(
                    "SELECT content_entry_id, value_longtext
                       FROM cms_content_entry_values
                      WHERE field_id = ? AND content_entry_id IN ({$ph})"
                );
                $stmt->execute(array_merge([$excerptFieldId], $entryIds));
                foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $r) {
                    $excerptByEntryId[(int) $r['content_entry_id']] = (string) ($r['value_longtext'] ?? '');
                }
            } catch (\PDOException) {
                // ignore
            }
        }

        // Shape into the same `index_entries` array the existing template
        // expects from the core CMS index route. Each item carries:
        //   row.{id,title,slug,published_at}, featured_url, featured_media_id,
        //   excerpt_plain, plus our own iata + min_avios additions used by the
        //   pill filter.
        $indexEntries = [];
        foreach ($entries as $row) {
            $eid = (int) $row['id'];
            $iata = strtoupper(trim((string) ($row['iata'] ?? '')));
            $featuredId = $row['featured_image_id'] !== null ? (int) $row['featured_image_id'] : 0;
            $imagePath = (string) ($row['image_path'] ?? '');

            $rawExcerpt = strip_tags($excerptByEntryId[$eid] ?? '');
            $rawExcerpt = trim(preg_replace('/\s+/u', ' ', $rawExcerpt) ?? '');
            $excerpt = $rawExcerpt !== ''
                ? (mb_strlen($rawExcerpt) > 180 ? mb_substr($rawExcerpt, 0, 177) . '…' : $rawExcerpt)
                : '';

            $cabinMin = $minByIataCabin[$iata] ?? [];
            $indexEntries[] = [
                'row' => [
                    'id' => $eid,
                    'title' => (string) ($row['title'] ?? ''),
                    'slug' => (string) ($row['slug'] ?? ''),
                    'published_at' => $row['published_at'],
                    'featured_image_id' => $featuredId,
                ],
                'featured_url' => $imagePath,
                'featured_media_id' => $featuredId > 0 ? $featuredId : null,
                'excerpt_plain' => $excerpt,
                'iata' => $iata,
                'min_avios' => $minByIata[$iata] ?? 0,
                // Per-cabin minimum (slug → avios). Cabins not flown by BA
                // for this IATA are simply absent — easier for the client
                // to test "isset" than to interpret 0 or null.
                'min_avios_by_cabin' => $cabinMin,
            ];
        }

        // Pre-compute the filter bucket counts (matches the pill thresholds in
        // the template). Done server-side so the pills always reflect the real
        // catalogue, not just whatever JS managed to count.
        //
        // Counts are computed for the "all cabins" view AND for each cabin
        // slug so the threshold pill labels can be updated instantly when the
        // user picks a cabin. Keys are `<cabinSlug>__all` and
        // `<cabinSlug>__under_<N>` (with the special slug "any" for the
        // "all cabins" default view).
        $thresholds = [10000, 15000, 25000, 50000];
        $counts = [];
        $cabinAvailability = ['any' => count($indexEntries)];
        foreach (array_keys($cabinLabels) as $slug) {
            $cabinAvailability[$slug] = 0;
        }

        $bucketsFor = static function (int $a) use ($thresholds): array {
            $hits = [];
            foreach ($thresholds as $t) {
                if ($a > 0 && $a <= $t) {
                    $hits[] = $t;
                }
            }
            return $hits;
        };

        // Initialise zeros so every key exists, simplifying template lookups.
        foreach (['any', ...array_keys($cabinLabels)] as $slug) {
            $counts[$slug . '__all'] = 0;
            foreach ($thresholds as $t) {
                $counts[$slug . '__under_' . $t] = 0;
            }
        }

        foreach ($indexEntries as $row) {
            // "Any cabin" view uses the overall min.
            $anyMin = (int) ($row['min_avios'] ?? 0);
            $counts['any__all']++;
            foreach ($bucketsFor($anyMin) as $t) {
                $counts['any__under_' . $t]++;
            }

            // Per-cabin: a destination only contributes to a cabin's counts
            // when that cabin actually has a fare for it.
            foreach ($cabinLabels as $slug => $_label) {
                $cabinMin = (int) ($row['min_avios_by_cabin'][$slug] ?? 0);
                if ($cabinMin <= 0) {
                    continue;
                }
                $counts[$slug . '__all']++;
                $cabinAvailability[$slug]++;
                foreach ($bucketsFor($cabinMin) as $t) {
                    $counts[$slug . '__under_' . $t]++;
                }
            }
        }

        // Trimmed payload for the interactive map view. Includes only the
        // fields the client needs to render a marker + popup; the heavier
        // grid card data (excerpts, srcsets, etc.) is intentionally omitted
        // to keep the inline JSON small. The client looks up lat/lng for
        // each IATA from themes/avios/assets/data/airports.json.
        $mapPoints = [];
        foreach ($indexEntries as $row) {
            $iata = (string) ($row['iata'] ?? '');
            if ($iata === '') {
                continue;
            }
            $mediaId = $row['featured_media_id'] ?? null;
            $thumb = $mediaId !== null
                ? '/media-rs/320/' . (int) $mediaId
                : ($row['featured_url'] ?: '');
            $mapPoints[] = [
                'id' => (int) $row['row']['id'],
                'title' => (string) $row['row']['title'],
                'slug' => (string) $row['row']['slug'],
                'iata' => $iata,
                'min_avios' => (int) ($row['min_avios'] ?? 0),
                'min_avios_by_cabin' => $row['min_avios_by_cabin'] ?? [],
                'thumb' => $thumb,
            ];
        }

        // Theme templates are on the default Twig loader path, so the bare
        // template name resolves without a namespace prefix.
        return $twig->render($response, 'content/destinations/index.twig', $ctx->viewData([
            'page_title' => 'Avios destinations',
            'content_type' => $type,
            'content_index_title' => $type->name,
            'content_index_description' => $type->description ?? '',
            'index_entries' => $indexEntries,
            'index_total' => count($indexEntries),
            'index_page' => 1,
            'index_per_page' => count($indexEntries),
            'index_total_pages' => 1,
            'index_pager_items' => [],
            // Filter bucket counts surfaced to the template so pills can show
            // "Under 15k (32)" labels. Keyed `<cabin>__all` and
            // `<cabin>__under_<N>` where cabin ∈ {any, economy, premium,
            // business, first}.
            'dest_filter_counts' => $counts,
            'dest_filter_thresholds' => $thresholds,
            // Cabin metadata for the cabin filter row. `labels` maps slug →
            // display label; `availability` maps slug → number of cards that
            // have a fare in that cabin (used to gray out unavailable pills
            // when only a handful of destinations support First, etc.).
            'dest_cabin_labels' => $cabinLabels,
            'dest_cabin_availability' => $cabinAvailability,
            // Map view data — empty array if there are no destinations with
            // an IATA code, which causes the template to skip the map UI.
            'dest_map_points' => $mapPoints,
        ]));
    })->setName('plugin.avios_destination_review.public_index');
};
