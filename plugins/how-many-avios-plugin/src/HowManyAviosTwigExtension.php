<?php

declare(strict_types=1);

namespace HowManyAviosPlugin;

use PDO;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Exposes plugin data to Twig templates.
 *
 * Functions:
 *   - hma_random_fare(cabin = 'Business') → one random fare row, or null if the table is empty.
 *     Used by the home hero to surface a "Today's Top Deal" sourced from hma_fares.
 *   - hma_fares_for_iata(iata) → structured peak/off-peak grid for a single destination.
 *     Used by destination review pages to surface the live fare table.
 */
final class HowManyAviosTwigExtension extends AbstractExtension
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('hma_random_fare', $this->randomFare(...)),
            new TwigFunction('hma_fares_for_iata', $this->faresForIata(...)),
            new TwigFunction('hma_asset', $this->asset(...)),
        ];
    }

    /**
     * URL helper for the plugin's static assets (CSS / JS / images).
     *
     * Mirrors the theme's `theme_asset()`: returns the public URL with
     * a `?v=<filemtime>` cache buster appended, so browsers can long-
     * cache the response (`immutable` headers from the asset route) and
     * still pick up changes automatically when a developer edits a
     * file. Falls back gracefully to no version when the file is
     * missing — the asset route will 404 in that case rather than
     * silently serving stale bytes.
     *
     * Usage in templates:
     *   <link rel="stylesheet" href="{{ hma_asset('css/how-many-avios.css') }}">
     *   <script defer src="{{ hma_asset('js/how-many-avios.js') }}"></script>
     */
    public function asset(string $relativePath): string
    {
        $clean = ltrim($relativePath, '/');
        $base = '/plugins/how-many-avios-plugin/assets/';

        $diskPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $clean);
        $mtime = @filemtime($diskPath);
        $bust = $mtime !== false ? ('?v=' . $mtime) : '';

        return $base . $clean . $bust;
    }

    /**
     * Pick a random fare. Biased toward `Business` by default because "top deal" in the
     * Avios world is almost always a premium-cabin redemption. Falls back to any cabin if
     * the requested one has no rows.
     *
     * @return array{
     *   iata:string, destination:string, cabin:string, season:string,
     *   avios_amount:int, gbp_amount:?string, route_label:string, season_label:string,
     *   destination_href:?string
     * }|null
     */
    public function randomFare(string $cabin = 'Business'): ?array
    {
        if (!in_array($cabin, FareRepository::CABINS, true)) {
            $cabin = 'Business';
        }

        $stmt = $this->pdo->prepare(
            'SELECT iata, destination, cabin, season, avios_amount, gbp_amount
               FROM hma_fares
              WHERE cabin = ?
              ORDER BY RAND()
              LIMIT 1'
        );
        $stmt->execute([$cabin]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            $stmt = $this->pdo->query(
                'SELECT iata, destination, cabin, season, avios_amount, gbp_amount
                   FROM hma_fares ORDER BY RAND() LIMIT 1'
            );
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        if ($row === false) {
            return null;
        }

        $row['avios_amount'] = (int) $row['avios_amount'];
        $row['route_label'] = $row['destination'] . ' ' . $row['cabin'] . ' Class from ' . number_format($row['avios_amount']) . ' Avios';
        $row['season_label'] = $row['season'] === 'Peak' ? 'Peak fare' : 'Off-peak fare';
        $row['destination_href'] = $this->publishedDestinationHrefForIata((string) $row['iata']);

        return $row;
    }

    /**
     * When a published CMS entry exists under content type `destinations` with the same
     * IATA (custom field `iata`), return its public path. Used by the homepage top-deal
     * card so "View deal" can open the destination guide instead of only the calculator.
     */
    private function publishedDestinationHrefForIata(string $iata): ?string
    {
        $iata = strtoupper(trim($iata));
        if (strlen($iata) !== 3) {
            return null;
        }

        try {
            $stmt = $this->pdo->prepare(
                'SELECT e.slug
                   FROM cms_content_entries e
                  INNER JOIN cms_content_types t ON t.id = e.content_type_id AND t.slug = ?
                  INNER JOIN cms_content_fields f ON f.content_type_id = t.id AND f.field_key = ?
                  INNER JOIN cms_content_entry_values v ON v.content_entry_id = e.id AND v.field_id = f.id
                  WHERE e.status = ?
                    AND UPPER(TRIM(v.value_longtext)) = ?
                  LIMIT 1'
            );
            $stmt->execute(['destinations', 'iata', 'published', $iata]);
            $slug = $stmt->fetchColumn();
        } catch (\PDOException) {
            return null;
        }

        $slug = is_string($slug) ? trim($slug) : '';
        if ($slug === '') {
            return null;
        }

        return '/destinations/' . $slug;
    }

    /**
     * All known peak/off-peak fares for a destination, grouped by cabin in BA's
     * canonical order. Returned shape (used directly by the destination review template):
     *
     *   {
     *     iata: "ABZ",
     *     destination: "Aberdeen",
     *     min_avios: 9000,
     *     cabins: [
     *       { cabin: "Economy", peak: { avios: 9000, gbp: 35.00 }, off_peak: { avios: 8000, gbp: 35.00 } },
     *       { cabin: "Business", peak: null, off_peak: { avios: 25000, gbp: 50.00 } },
     *       ...
     *     ]
     *   }
     *
     * Returns null when nothing matches (so templates can {% if hma_fares %} guard).
     *
     * @return array{
     *   iata:string, destination:string, min_avios:int,
     *   cabins:list<array{
     *     cabin:string,
     *     peak:?array{avios:int, gbp:?float},
     *     off_peak:?array{avios:int, gbp:?float}
     *   }>
     * }|null
     */
    public function faresForIata(string $iata): ?array
    {
        $iata = strtoupper(trim($iata));
        if (strlen($iata) !== 3) {
            return null;
        }

        try {
            $stmt = $this->pdo->prepare(
                'SELECT iata, destination, cabin, season, avios_amount, gbp_amount
                   FROM hma_fares
                  WHERE iata = ?
                  ORDER BY FIELD(cabin, \'Economy\',\'Premium Economy\',\'Business\',\'First\'), season'
            );
            $stmt->execute([$iata]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\PDOException) {
            return null;
        }

        if ($rows === []) {
            return null;
        }

        // Group by cabin, keeping season pair for each.
        $byCabin = [];
        $destination = '';
        $minAvios = PHP_INT_MAX;
        foreach ($rows as $r) {
            $cabin = (string) $r['cabin'];
            $season = (string) $r['season'];
            $destination = (string) $r['destination'];
            $avios = (int) $r['avios_amount'];
            $gbp = $r['gbp_amount'] !== null ? (float) $r['gbp_amount'] : null;
            $byCabin[$cabin] ??= ['cabin' => $cabin, 'peak' => null, 'off_peak' => null];
            $key = $season === 'Peak' ? 'peak' : 'off_peak';
            $byCabin[$cabin][$key] = ['avios' => $avios, 'gbp' => $gbp];
            if ($avios > 0 && $avios < $minAvios) {
                $minAvios = $avios;
            }
        }

        // Re-order cabins canonically (BA standard).
        $order = ['Economy', 'Premium Economy', 'Business', 'First'];
        $ordered = [];
        foreach ($order as $c) {
            if (isset($byCabin[$c])) {
                $ordered[] = $byCabin[$c];
            }
        }

        return [
            'iata' => $iata,
            'destination' => $destination,
            'min_avios' => $minAvios === PHP_INT_MAX ? 0 : $minAvios,
            'cabins' => $ordered,
        ];
    }
}
