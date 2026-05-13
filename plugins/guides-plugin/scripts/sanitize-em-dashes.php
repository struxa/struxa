<?php

declare(strict_types=1);

use App\Cli\CmsCliEnv;
use GuidesPlugin\TextDeAi;

/**
 * One-off sanitiser for already-imported guides.
 *
 * Replays the full TextDeAi cleanup pipeline against every guide's
 * title, SEO copy, body HTML and summary. The pipeline
 * currently covers:
 *
 *   * AI-tell em-dashes (" — ") rewritten to colons in headlines and
 *     commas in prose.
 *   * Broken hyphens left by the upstream scraper ("non?refundable",
 *     "2?4?1", "long?haul") repaired back to real hyphens.
 *
 * Future imports are scrubbed automatically by GuidesImporter, so this
 * script only needs to be run when:
 *
 *   * The corpus existed BEFORE the de-AI logic was added/extended, or
 *   * Someone hand-edits an entry back into the old style and wants it
 *     normalised again.
 *
 * Usage (inside the app container):
 *   docker compose exec app php plugins/guides-plugin/scripts/sanitize-em-dashes.php [--dry-run]
 *
 * Flags:
 *   --dry-run   Don't write anything. Just print what would change.
 *
 * Exit codes:
 *   0  Run completed (see summary for what changed).
 *   1  Fatal error (bad CLI args, DB connection, etc).
 */

$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';

$envPath = $root . '/.env';
if (is_readable($envPath)) {
    Dotenv\Dotenv::createImmutable($root)->safeLoad();
}

// Same one-file PSR-4 shim as scripts/import-tsv.php — CLI scripts boot
// outside the HTTP lifecycle so Composer's generated autoload doesn't
// know about plugin classes yet.
$pluginRoot = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($pluginRoot): void {
    $prefix = 'GuidesPlugin\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $rel  = substr($class, strlen($prefix));
    $file = $pluginRoot . '/src/' . str_replace('\\', DIRECTORY_SEPARATOR, $rel) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

$dryRun = in_array('--dry-run', $_SERVER['argv'] ?? [], true);

$pdo = makePdo();
$deAi = new TextDeAi();

$typeIdStmt = $pdo->prepare('SELECT id FROM cms_content_types WHERE slug = ? LIMIT 1');
$typeIdStmt->execute(['guides']);
$typeId = $typeIdStmt->fetchColumn();
if ($typeId === false) {
    fwrite(STDERR, "Error: 'guides' content type not found.\n");
    exit(1);
}
$typeId = (int) $typeId;

// Map field_key → field id so we can route per-field sanitisation.
$fieldMap = [];
$rs = $pdo->prepare('SELECT id, field_key FROM cms_content_fields WHERE content_type_id = ?');
$rs->execute([$typeId]);
foreach ($rs->fetchAll() as $row) {
    $fieldMap[$row['field_key']] = (int) $row['id'];
}

$entries = $pdo->prepare(
    'SELECT id, title, seo_title, seo_description
       FROM cms_content_entries
      WHERE content_type_id = ?
      ORDER BY id'
);
$entries->execute([$typeId]);
$allEntries = $entries->fetchAll();

echo 'Guides content sweep (em-dashes + broken hyphens)' . ($dryRun ? ' [dry-run]' : '') . "\n";
echo "  Entries to inspect: " . count($allEntries) . "\n";
echo str_repeat('-', 60) . "\n";

$startedAt = microtime(true);
$stats = [
    'entries_changed' => 0,
    'title_writes'    => 0,
    'seo_writes'      => 0,
    'body_writes'     => 0,
    'summary_writes'  => 0,
];

// Prepared statements reused across all entries.
$updateEntry = $pdo->prepare(
    'UPDATE cms_content_entries
        SET title = :title,
            seo_title = :seo_title,
            seo_description = :seo_description,
            updated_at = CURRENT_TIMESTAMP
      WHERE id = :id'
);
$loadValue = $pdo->prepare(
    'SELECT value_longtext
       FROM cms_content_entry_values
      WHERE content_entry_id = ? AND field_id = ?'
);
$updateValue = $pdo->prepare(
    'UPDATE cms_content_entry_values
        SET value_longtext = :v
      WHERE content_entry_id = :e AND field_id = :f'
);

foreach ($allEntries as $entry) {
    $entryId = (int) $entry['id'];
    $title   = (string) $entry['title'];
    $seoT    = (string) ($entry['seo_title'] ?? '');
    $seoD    = (string) ($entry['seo_description'] ?? '');

    $newTitle = $deAi->sanitiseTitle($title);
    $newSeoT  = $seoT === '' ? '' : $deAi->sanitiseTitle($seoT);
    $newSeoD  = $seoD === '' ? '' : $deAi->sanitiseExcerpt($seoD);

    $rowChanged = false;

    // Entry row (title + SEO copy) — single UPDATE if any of the three changed.
    if ($newTitle !== $title || $newSeoT !== $seoT || $newSeoD !== $seoD) {
        $rowChanged = true;
        $stats['title_writes']++;
        if ($newSeoT !== $seoT) {
            $stats['seo_writes']++;
        }
        if (!$dryRun) {
            $updateEntry->execute([
                ':title'           => $newTitle,
                ':seo_title'       => $newSeoT === '' ? null : $newSeoT,
                ':seo_description' => $newSeoD === '' ? null : $newSeoD,
                ':id'              => $entryId,
            ]);
        }
    }

    // Field values: body (HTML), summary (prose).
    foreach (['body', 'summary'] as $key) {
        if (!isset($fieldMap[$key])) {
            continue;
        }
        $fieldId = $fieldMap[$key];

        $loadValue->execute([$entryId, $fieldId]);
        $current = $loadValue->fetchColumn();
        if ($current === false || $current === null || $current === '') {
            continue;
        }
        $current = (string) $current;

        $cleaned = match ($key) {
            'body'    => $deAi->sanitiseBodyHtml($current),
            'summary' => $deAi->sanitiseExcerpt($current),
        };

        if ($cleaned === $current) {
            continue;
        }

        $rowChanged = true;
        $stats[$key . '_writes']++;
        if (!$dryRun) {
            $updateValue->execute([
                ':v' => $cleaned,
                ':e' => $entryId,
                ':f' => $fieldId,
            ]);
        }
    }

    if ($rowChanged) {
        $stats['entries_changed']++;
        $marker = $dryRun ? '?' : '*';
        $shortTitle = mb_substr($newTitle, 0, 72, 'UTF-8');
        printf("  %s [%4d] %s\n", $marker, $entryId, $shortTitle);
    }
}

$elapsed = number_format(microtime(true) - $startedAt, 2);

echo str_repeat('-', 60) . "\n";
echo "Done in {$elapsed}s\n";
echo "  Entries changed:        {$stats['entries_changed']}\n";
echo "  Title rows updated:     {$stats['title_writes']}\n";
echo "    (of those, SEO too):  {$stats['seo_writes']}\n";
echo "  Body fields updated:    {$stats['body_writes']}\n";
echo "  Summary fields updated: {$stats['summary_writes']}\n";
if ($dryRun) {
    echo "\n(Dry run — no changes were written. Re-run without --dry-run to apply.)\n";
}

exit(0);

// ─── helpers ──────────────────────────────────────────────────────────────

function makePdo(): PDO
{
    $dbHost = CmsCliEnv::get('DB_HOST', '127.0.0.1');
    $dbPort = CmsCliEnv::get('DB_PORT', '3306');
    $dbName = CmsCliEnv::get('DB_NAME', 'studio');
    $dbUser = CmsCliEnv::get('DB_USER', 'studio');
    $dbPass = CmsCliEnv::get('DB_PASS', 'studio');
    $dsn    = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $dbHost, $dbPort, $dbName);
    return new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}
