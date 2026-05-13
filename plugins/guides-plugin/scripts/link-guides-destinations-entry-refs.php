#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Auto-fill entry_refs between Guides and Destinations by matching each
 * destination's IATA code as a whole word in a guide's body (HTML stripped).
 *
 * Prerequisites:
 *   - Run core migration `036_cms_content_fields_entry_refs.sql` (ENUM includes entry_refs).
 *   - Run `002_guides_related_destinations_field.sql` and `004_adr_related_guides_field.sql`
 *     (or add equivalent fields in admin).
 *
 * Usage (from project root, with DB_* in .env):
 *   php plugins/guides-plugin/scripts/link-guides-destinations-entry-refs.php [--dry-run] [--overwrite] [--only=both|destinations|guides]
 *
 *   --dry-run      Print planned links only; no DB writes.
 *   --overwrite    Replace existing JSON instead of merging with current IDs.
 *   --only=        destinations = fill destinations.related_guides only;
 *                  guides        = fill guides.related_destinations only;
 *                  both          = default, run both directions.
 */

use App\Cli\CmsCliEnv;
use App\Content\ContentEntryReferenceIds;

$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';

$envPath = $root . '/.env';
if (is_readable($envPath)) {
    Dotenv\Dotenv::createImmutable($root)->safeLoad();
}

$opts = parseLinkArgs($_SERVER['argv'] ?? []);

$pdo = makePdo();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$fieldDest = fieldId($pdo, 'destinations', 'related_guides');
$fieldGuide = fieldId($pdo, 'guides', 'related_destinations');
if ($fieldDest === null || $fieldGuide === null) {
    fwrite(STDERR, "Missing field(s). Apply migrations:\n");
    fwrite(STDERR, "  database/migrations/036_cms_content_fields_entry_refs.sql\n");
    fwrite(STDERR, "  plugins/guides-plugin/migrations/002_guides_related_destinations_field.sql\n");
    fwrite(STDERR, "  plugins/avios-destination-review-plugin/migrations/004_adr_related_guides_field.sql\n");
    exit(1);
}

$destRows = loadPublishedDestinationsWithIata($pdo);
$guideRows = loadPublishedGuidesWithBody($pdo);

if ($destRows === [] || $guideRows === []) {
    fwrite(STDERR, "Nothing to link (destinations with IATA: " . count($destRows) . ", guides with body: " . count($guideRows) . ").\n");
    exit(0);
}

$iataToDestId = [];
foreach ($destRows as $r) {
    $iata = strtoupper(trim((string) ($r['iata'] ?? '')));
    if (strlen($iata) !== 3 || !ctype_alpha($iata)) {
        continue;
    }
    if (!isset($iataToDestId[$iata])) {
        $iataToDestId[$iata] = (int) $r['id'];
    }
}

$stats = ['dest_updated' => 0, 'guide_updated' => 0, 'dest_skipped' => 0, 'guide_skipped' => 0];

if ($opts['only'] === 'both' || $opts['only'] === 'destinations') {
    foreach ($destRows as $d) {
        $destId = (int) $d['id'];
        $iata = strtoupper(trim((string) ($d['iata'] ?? '')));
        if (strlen($iata) !== 3) {
            continue;
        }
        $pattern = '/\b' . preg_quote($iata, '/') . '\b/iu';
        $matchedGuideIds = [];
        foreach ($guideRows as $g) {
            $body = (string) ($g['body'] ?? '');
            if ($body === '') {
                continue;
            }
            $plain = strip_tags($body);
            if (preg_match($pattern, $plain) === 1) {
                $matchedGuideIds[] = (int) $g['id'];
            }
        }
        $matchedGuideIds = array_values(array_unique($matchedGuideIds));
        sort($matchedGuideIds);
        $matchedGuideIds = array_slice($matchedGuideIds, 0, 12);
        if ($matchedGuideIds === []) {
            ++$stats['dest_skipped'];
            continue;
        }
        $json = mergeRefJson($pdo, $destId, $fieldDest, $matchedGuideIds, $opts['overwrite']);
        if (!$opts['dry_run']) {
            upsertValue($pdo, $destId, $fieldDest, $json);
        }
        ++$stats['dest_updated'];
        if ($opts['dry_run']) {
            echo "[dry-run] destination #{$destId} ({$iata}) → guides " . $json . "\n";
        }
    }
}

if ($opts['only'] === 'both' || $opts['only'] === 'guides') {
    $iataList = array_keys($iataToDestId);
    usort($iataList, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

    foreach ($guideRows as $g) {
        $guideId = (int) $g['id'];
        $body = (string) ($g['body'] ?? '');
        if ($body === '') {
            continue;
        }
        $plain = strtoupper(strip_tags($body));
        $foundDestIds = [];
        foreach ($iataList as $iata) {
            $pattern = '/\b' . preg_quote($iata, '/') . '\b/';
            if (preg_match($pattern, $plain) === 1) {
                $foundDestIds[] = $iataToDestId[$iata];
            }
        }
        $foundDestIds = array_values(array_unique($foundDestIds));
        $foundDestIds = array_slice($foundDestIds, 0, 12);
        if ($foundDestIds === []) {
            ++$stats['guide_skipped'];
            continue;
        }
        $json = mergeRefJson($pdo, $guideId, $fieldGuide, $foundDestIds, $opts['overwrite']);
        if (!$opts['dry_run']) {
            upsertValue($pdo, $guideId, $fieldGuide, $json);
        }
        ++$stats['guide_updated'];
        if ($opts['dry_run']) {
            echo "[dry-run] guide #{$guideId} → destinations " . $json . "\n";
        }
    }
}

echo $opts['dry_run'] ? "Dry run complete.\n" : "Done.\n";
echo '  Destinations linked (related_guides): ' . $stats['dest_updated'] . "\n";
echo '  Guides linked (related_destinations): ' . $stats['guide_updated'] . "\n";
echo '  Destinations skipped (no body match): ' . $stats['dest_skipped'] . "\n";
echo '  Guides skipped (no IATA match):       ' . $stats['guide_skipped'] . "\n";

exit(0);

/**
 * @param list<string> $argv
 * @return array{dry_run: bool, overwrite: bool, only: 'both'|'destinations'|'guides'}
 */
function parseLinkArgs(array $argv): array
{
    $dry = false;
    $overwrite = false;
    $only = 'both';

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--dry-run') {
            $dry = true;
            continue;
        }
        if ($arg === '--overwrite') {
            $overwrite = true;
            continue;
        }
        if (str_starts_with($arg, '--only=')) {
            $v = strtolower(substr($arg, 7));
            if (!in_array($v, ['both', 'destinations', 'guides'], true)) {
                fwrite(STDERR, "Invalid --only value (use both, destinations, guides).\n");
                exit(1);
            }
            $only = $v;
            continue;
        }
        if ($arg === '--help' || $arg === '-h') {
            echo <<<'HELP'
Usage:
  php link-guides-destinations-entry-refs.php [--dry-run] [--overwrite] [--only=both|destinations|guides]

  --dry-run     Show planned JSON; do not write.
  --overwrite   Replace existing entry_refs instead of merging IDs.
  --only        Limit which direction runs (default: both).

HELP;
            exit(0);
        }
        fwrite(STDERR, "Unknown argument: {$arg}\n");
        exit(1);
    }

    return ['dry_run' => $dry, 'overwrite' => $overwrite, 'only' => $only];
}

function makePdo(): PDO
{
    $dbHost = CmsCliEnv::get('DB_HOST', '127.0.0.1');
    $dbPort = CmsCliEnv::get('DB_PORT', '3306');
    $dbName = CmsCliEnv::get('DB_NAME', 'studio');
    $dbUser = CmsCliEnv::get('DB_USER', 'studio');
    $dbPass = CmsCliEnv::get('DB_PASS', 'studio');
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $dbHost, $dbPort, $dbName);

    return new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

function fieldId(PDO $pdo, string $typeSlug, string $fieldKey): ?int
{
    $stmt = $pdo->prepare(
        'SELECT f.id
           FROM cms_content_fields f
          INNER JOIN cms_content_types t ON t.id = f.content_type_id
          WHERE t.slug = ? AND f.field_key = ?
          LIMIT 1'
    );
    $stmt->execute([$typeSlug, $fieldKey]);
    $v = $stmt->fetchColumn();

    return $v !== false ? (int) $v : null;
}

/** @return list<array{id:int, iata:string}> */
function loadPublishedDestinationsWithIata(PDO $pdo): array
{
    $sql = <<<'SQL'
        SELECT e.id, TRIM(v.value_longtext) AS iata
          FROM cms_content_entries e
         INNER JOIN cms_content_types t ON t.id = e.content_type_id AND t.slug = 'destinations'
         INNER JOIN cms_content_fields fi ON fi.content_type_id = t.id AND fi.field_key = 'iata'
          LEFT JOIN cms_content_entry_values v ON v.content_entry_id = e.id AND v.field_id = fi.id
         WHERE e.status = 'published'
           AND (e.published_at IS NULL OR e.published_at <= NOW())
SQL;

    return $pdo->query($sql)->fetchAll() ?: [];
}

/** @return list<array{id:int, body:string}> */
function loadPublishedGuidesWithBody(PDO $pdo): array
{
    $sql = <<<'SQL'
        SELECT e.id, IFNULL(v.value_longtext, '') AS body
          FROM cms_content_entries e
         INNER JOIN cms_content_types t ON t.id = e.content_type_id AND t.slug = 'guides'
         INNER JOIN cms_content_fields fb ON fb.content_type_id = t.id AND fb.field_key = 'body'
          LEFT JOIN cms_content_entry_values v ON v.content_entry_id = e.id AND v.field_id = fb.id
         WHERE e.status = 'published'
           AND (e.published_at IS NULL OR e.published_at <= NOW())
SQL;

    return $pdo->query($sql)->fetchAll() ?: [];
}

/**
 * @param list<int> $newIds
 */
function mergeRefJson(PDO $pdo, int $entryId, int $fieldId, array $newIds, bool $overwrite): string
{
    if ($overwrite) {
        return ContentEntryReferenceIds::toJson($newIds);
    }
    $stmt = $pdo->prepare(
        'SELECT value_longtext FROM cms_content_entry_values WHERE content_entry_id = ? AND field_id = ? LIMIT 1'
    );
    $stmt->execute([$entryId, $fieldId]);
    $existing = $stmt->fetchColumn();
    $existing = is_string($existing) ? $existing : '';
    $merged = array_merge(ContentEntryReferenceIds::parse($existing), $newIds);

    return ContentEntryReferenceIds::toJson($merged);
}

function upsertValue(PDO $pdo, int $entryId, int $fieldId, string $json): void
{
    $sql = 'INSERT INTO cms_content_entry_values (content_entry_id, field_id, value_longtext)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE value_longtext = VALUES(value_longtext)';
    $pdo->prepare($sql)->execute([$entryId, $fieldId, $json]);
}
