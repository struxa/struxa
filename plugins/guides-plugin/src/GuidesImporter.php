<?php

declare(strict_types=1);

namespace GuidesPlugin;

use App\Content\ContentEntryRepository;
use App\Content\ContentEntryValueRepository;
use App\Content\ContentFieldRepository;
use App\Content\ContentTypeRepository;
use PDO;

/**
 * Imports rows from the AI-rewritten articles TSV into the "guides" CMS
 * content type.
 *
 * High-level flow per row:
 *   1. Skip rows whose `legacy_id` is already in `guides_imports` (unless
 *      $refresh is true, in which case we overwrite the values on the
 *      mapped entry).
 *   2. Generate a URL-safe slug from `seo_title`. Append `-N` suffixes if a
 *      different content entry already owns the slug (e.g. an old import,
 *      destination with a clashing name) so we never violate the (type,
 *      slug) uniqueness contract.
 *   3. Insert a `cms_content_entries` row with status='published' and the
 *      SEO title/description fields seeded from the TSV.
 *   4. Insert/upsert the rich-text body, summary, and word-count into
 *      `cms_content_entry_values`.
 *   5. Record (legacy_id → entry_id) in `guides_imports` so future runs
 *      can skip-or-update.
 *
 * The importer is intentionally chatty via $progress callback so the CLI
 * and admin UI can both show a live count. It also returns a summary
 * struct describing how many rows it imported, updated and skipped.
 *
 * @phpstan-type ImportSummary array{
 *   total:int,
 *   imported:int,
 *   updated:int,
 *   skipped:int,
 *   skipped_empty:int,
 *   errors: list<string>
 * }
 */
final class GuidesImporter
{
    public const TYPE_SLUG = 'guides';

    /**
     * Maps each content_field's `field_key` to its TSV column.
     * The body field uses the rich-text article HTML; the summary field
     * gets the short marketing blurb; word_count is stored when present.
     *
     * Keep this list aligned with migrations/001_guides_content_type.sql.
     */
    private const FIELD_KEY_TO_COLUMN = [
        'body'         => 'article_html',
        'summary'      => 'short_summary',
        'word_count'   => 'word_count',
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly ContentTypeRepository $types,
        private readonly ContentEntryRepository $entries,
        private readonly ContentFieldRepository $fields,
        private readonly ContentEntryValueRepository $values,
        private readonly TsvParser $parser,
        private readonly TextDeAi $deAi = new TextDeAi(),
    ) {
    }

    public static function fromPdo(PDO $pdo): self
    {
        return new self(
            $pdo,
            new ContentTypeRepository($pdo),
            new ContentEntryRepository($pdo),
            new ContentFieldRepository($pdo),
            new ContentEntryValueRepository($pdo),
            new TsvParser(),
            new TextDeAi(),
        );
    }

    /**
     * Check the migration has run and we have a content type + body field
     * to write into. Used by the admin UI to render a "run migrations" hint
     * rather than die on the first INSERT.
     */
    public function isReady(): bool
    {
        $type = $this->types->findBySlug(self::TYPE_SLUG);
        if ($type === null) {
            return false;
        }
        // body field is the only one strictly required; others are nice-to-have.
        foreach ($this->fields->forTypeOrdered($type->id) as $f) {
            if ($f->fieldKey === 'body') {
                return true;
            }
        }
        return false;
    }

    /**
     * Import every row from $tsvPath.
     *
     * @param callable(int $current, int $total, string $title): void|null $progress
     *        Optional callback called once per row so callers can stream progress.
     * @return ImportSummary
     */
    public function importFile(string $tsvPath, bool $refresh = false, ?callable $progress = null, ?int $limit = null): array
    {
        $type = $this->types->findBySlug(self::TYPE_SLUG);
        if ($type === null) {
            throw new \RuntimeException('The "guides" content type does not exist. Run plugin migrations first.');
        }
        $typeId = $type->id;

        $fieldIds = $this->resolveFieldIds($typeId);
        if (!isset($fieldIds['body'])) {
            throw new \RuntimeException('The "body" content field is missing on the guides content type.');
        }

        $summary = [
            'total'         => 0,
            'imported'      => 0,
            'updated'       => 0,
            'skipped'       => 0,
            'skipped_empty' => 0,
            'errors'        => [],
        ];

        $alreadyMapped = $this->loadLegacyMapping();

        $rows = $this->parser->parseFile($tsvPath);
        $rowIndex = 0;
        foreach ($rows as $row) {
            $rowIndex++;
            $summary['total']++;

            if ($limit !== null && $summary['imported'] >= $limit) {
                break;
            }

            $title = trim($row['seo_title']);
            $body  = trim($row['article_html']);
            if ($title === '' || $body === '' || $body === '<article>' || $body === '<article></article>') {
                $summary['skipped_empty']++;
                if ($progress !== null) {
                    $progress($rowIndex, $summary['total'], '(empty) ' . ($title !== '' ? $title : '#' . $row['id']));
                }
                continue;
            }

            $legacyId = $row['id'];
            if ($legacyId <= 0) {
                $summary['errors'][] = sprintf('Row %d: missing or invalid legacy id.', $rowIndex);
                continue;
            }

            $existingEntryId = $alreadyMapped[$legacyId] ?? null;
            if ($existingEntryId !== null && !$refresh) {
                $summary['skipped']++;
                if ($progress !== null) {
                    $progress($rowIndex, $summary['total'], '(skip) ' . $title);
                }
                continue;
            }

            try {
                $this->pdo->beginTransaction();

                if ($existingEntryId !== null) {
                    $this->updateEntry($existingEntryId, $row, $fieldIds);
                    $entryId = $existingEntryId;
                    $summary['updated']++;
                } else {
                    $entryId = $this->createEntry($typeId, $row, $fieldIds);
                    $this->recordMapping($legacyId, $entryId, $row);
                    $alreadyMapped[$legacyId] = $entryId;
                    $summary['imported']++;
                }

                $this->pdo->commit();
            } catch (\Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                $summary['errors'][] = sprintf('Row %d (#%d "%s"): %s', $rowIndex, $legacyId, $title, $e->getMessage());
            }

            if ($progress !== null) {
                $progress($rowIndex, $summary['total'], $title);
            }
        }

        return $summary;
    }

    /**
     * Map field_key → field_id for the guides content type. Missing fields
     * (e.g. word_count if someone manually deleted it) are simply omitted
     * from the map so we don't write to non-existent columns.
     *
     * @return array<string, int>
     */
    private function resolveFieldIds(int $typeId): array
    {
        $out = [];
        foreach ($this->fields->forTypeOrdered($typeId) as $f) {
            if (array_key_exists($f->fieldKey, self::FIELD_KEY_TO_COLUMN)) {
                $out[$f->fieldKey] = $f->id;
            }
        }
        return $out;
    }

    /**
     * Load every legacy_id → entry_id pair already recorded by the
     * importer, so the next pass can quickly tell what's new vs seen.
     *
     * @return array<int, int>
     */
    private function loadLegacyMapping(): array
    {
        $out = [];
        $stmt = $this->pdo->query('SELECT legacy_id, entry_id FROM guides_imports WHERE entry_id IS NOT NULL');
        if ($stmt instanceof \PDOStatement) {
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $out[(int) $r['legacy_id']] = (int) $r['entry_id'];
            }
        }
        return $out;
    }

    /**
     * Insert a new cms_content_entries row + the per-field values.
     *
     * @param array<string,int> $fieldIds
     * @param array{
     *   id:int, seo_title:string, short_summary:string,
     *   article_html:string, word_count:string
     * } $row
     */
    private function createEntry(int $typeId, array $row, array $fieldIds): int
    {
        // Strip AI-tell em-dashes ( — ) from titles and SEO copy before
        // they ever hit the database. Headlines get the colon variant,
        // descriptive prose gets a comma. See TextDeAi for the rules.
        $title = $this->trimToLength($this->deAi->sanitiseTitle($row['seo_title']), 255);
        $slug  = $this->makeUniqueSlug($typeId, $title, $row['id']);

        $seoTitle = $this->trimToLength($this->deAi->sanitiseTitle($row['seo_title']), 255);
        $seoDesc  = $this->trimToLength($this->deAi->sanitiseExcerpt($row['short_summary']), 500);

        $entryId = $this->entries->insert(
            $typeId,
            $title,
            $slug,
            'published',
            null,                // featuredImageId
            $seoTitle,
            $seoDesc,
            null,                // canonicalUrl
            false,               // seoNoindex
            null,                // ogTitle
            null,                // ogDescription
            null,                // ogImageId
            null,                // twitterTitle
            null,                // twitterDescription
            null,                // twitterImageId
            null,                // schemaJson
            date('Y-m-d H:i:s'), // publishedAt
            null                 // createdBy
        );

        $this->writeFieldValues($entryId, $row, $fieldIds);

        return $entryId;
    }

    /**
     * Update an already-imported entry's mutable fields. We deliberately
     * leave the title/slug/SEO fields alone so editors don't lose hand
     * edits when the operator re-runs the importer with --refresh.
     *
     * @param array<string,int> $fieldIds
     * @param array{
     *   id:int, seo_title:string, short_summary:string,
     *   article_html:string, word_count:string
     * } $row
     */
    private function updateEntry(int $entryId, array $row, array $fieldIds): void
    {
        $this->writeFieldValues($entryId, $row, $fieldIds);

        // Stamp updated_at on the entry for cache-busting.
        $stmt = $this->pdo->prepare('UPDATE cms_content_entries SET updated_at = CURRENT_TIMESTAMP WHERE id = ?');
        $stmt->execute([$entryId]);
    }

    /**
     * @param array<string,int> $fieldIds
     * @param array<string,mixed> $row
     */
    private function writeFieldValues(int $entryId, array $row, array $fieldIds): void
    {
        foreach (self::FIELD_KEY_TO_COLUMN as $fieldKey => $col) {
            if (!isset($fieldIds[$fieldKey])) {
                continue;
            }
            $value = (string) ($row[$col] ?? '');
            // Field-specific de-AI rules. The body is HTML so we use the
            // heading-aware variant; the short summary is plain prose;
            // word_count is mechanical and shouldn't be touched.
            if ($value !== '') {
                $value = match ($fieldKey) {
                    'body'    => $this->deAi->sanitiseBodyHtml($value),
                    'summary' => $this->deAi->sanitiseExcerpt($value),
                    default   => $value,
                };
            }
            $this->values->upsert($entryId, $fieldIds[$fieldKey], $value === '' ? null : $value);
        }
    }

    /**
     * Record (or refresh) the legacy_id → entry_id mapping along with a
     * cheap source hash so a future "re-import only changed rows" mode can
     * be added without schema changes.
     */
    private function recordMapping(int $legacyId, int $entryId, array $row): void
    {
        $hash = sha1((string) ($row['article_html'] ?? '') . '|' . (string) ($row['seo_title'] ?? ''));
        $stmt = $this->pdo->prepare(
            'INSERT INTO guides_imports (legacy_id, entry_id, source_hash) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE entry_id = VALUES(entry_id), source_hash = VALUES(source_hash)'
        );
        $stmt->execute([$legacyId, $entryId, $hash]);
    }

    /**
     * Build a URL-safe slug from a title and ensure it's unique within the
     * given content type. We salt with the legacy_id when we have no other
     * way to disambiguate, so /guides/{slug} URLs stay stable across re-runs.
     */
    private function makeUniqueSlug(int $typeId, string $title, int $legacyId): string
    {
        $base = $this->slugify($title);
        if ($base === '') {
            $base = 'guide-' . $legacyId;
        }
        // The CMS column is VARCHAR(191) — leave 8 chars head-room for `-NNN` suffixes.
        $base = $this->trimToLength($base, 180);

        if (!$this->entries->slugExists($typeId, $base)) {
            return $base;
        }

        $candidate = $base . '-' . $legacyId;
        if (!$this->entries->slugExists($typeId, $candidate)) {
            return $candidate;
        }

        for ($n = 2; $n < 1000; $n++) {
            $next = $base . '-' . $legacyId . '-' . $n;
            if (!$this->entries->slugExists($typeId, $next)) {
                return $next;
            }
        }

        throw new \RuntimeException('Could not find an unused slug for: ' . $title);
    }

    /**
     * Turn a free-form title into a URL slug: lowercase ASCII, dashes
     * between words, no leading/trailing punctuation, no doubled dashes.
     */
    private function slugify(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        // Decode common HTML entities ("&amp;", "&#8217;" etc.) so they don't
        // survive into the slug as literal "amp" tokens.
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Transliterate Unicode punctuation/diacritics to ASCII where possible.
        if (function_exists('iconv')) {
            $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
            if (is_string($ascii) && $ascii !== '') {
                $text = $ascii;
            }
        }

        $text = strtolower($text);
        $text = preg_replace('/[^a-z0-9]+/u', '-', $text) ?? '';
        $text = trim($text, '-');
        $text = preg_replace('/-{2,}/', '-', $text) ?? $text;

        return $text;
    }

    /**
     * Byte-safe truncation that won't split a multi-byte character.
     */
    private function trimToLength(string $value, int $maxLen): string
    {
        $value = trim($value);
        if (mb_strlen($value, 'UTF-8') <= $maxLen) {
            return $value;
        }
        return rtrim(mb_substr($value, 0, $maxLen, 'UTF-8'));
    }
}
