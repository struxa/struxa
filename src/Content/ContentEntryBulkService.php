<?php

declare(strict_types=1);

namespace App\Content;

use App\Access\WorkflowService;
use App\Taxonomy\ContentEntryTaxonomyRepository;
use App\Taxonomy\EntryTaxonomySync;
use App\Taxonomy\TaxonomyRepository;
use App\Taxonomy\TaxonomyTermRepository;

/**
 * Bulk publish, trash, and taxonomy assignment for content entry lists.
 */
final class ContentEntryBulkService
{
    public function __construct(
        private readonly ContentEntryRepository $entries,
        private readonly ContentEntryTaxonomyRepository $entryTaxonomy,
        private readonly WorkflowService $workflow,
    ) {
    }

    /**
     * @param list<int|string> $rawIds
     * @param list<string> $permissionSlugs
     */
    public function publish(int $contentTypeId, array $rawIds, array $permissionSlugs): ContentEntryBulkResult
    {
        $rows = $this->rowsForBulk($contentTypeId, $rawIds);
        $applied = 0;
        $skipped = 0;
        $reasons = [];
        $appliedIds = [];

        foreach ($rows['missing'] as $id) {
            $skipped++;
            $reasons[] = 'Entry #' . $id . ' not found.';
        }

        foreach ($rows['rows'] as $row) {
            $entryId = (int) $row['id'];
            $from = (string) ($row['status'] ?? 'draft');
            if ($from === 'published') {
                continue;
            }
            if (!$this->workflow->canTransition($permissionSlugs, $from, 'published')) {
                $skipped++;
                $reasons[] = '#' . $entryId . ': cannot publish from ' . $from . '.';

                continue;
            }

            $publishedAt = $row['published_at'] ?? null;
            if ($publishedAt === null || $publishedAt === '') {
                $publishedAt = date('Y-m-d H:i:s');
            }

            $this->entries->update(
                $entryId,
                (string) $row['title'],
                (string) $row['slug'],
                'published',
                $this->nullableInt($row['featured_image_id'] ?? null),
                $this->nullableString($row['seo_title'] ?? null),
                $this->nullableString($row['seo_description'] ?? null),
                $this->nullableString($row['focus_keyphrase'] ?? null),
                $this->nullableString($row['canonical_url'] ?? null),
                ((int) ($row['seo_noindex'] ?? 0)) === 1,
                $this->nullableString($row['og_title'] ?? null),
                $this->nullableString($row['og_description'] ?? null),
                $this->nullableInt($row['og_image_id'] ?? null),
                $this->nullableString($row['twitter_title'] ?? null),
                $this->nullableString($row['twitter_description'] ?? null),
                $this->nullableInt($row['twitter_image_id'] ?? null),
                $this->nullableString($row['schema_json'] ?? null),
                $publishedAt,
                null,
                $this->nullableString($row['scheduled_unpublish_at'] ?? null),
            );
            $applied++;
            $appliedIds[] = $entryId;
        }

        return new ContentEntryBulkResult($applied, $skipped, $reasons, $appliedIds);
    }

    /**
     * @param list<int|string> $rawIds
     */
    public function trash(int $contentTypeId, array $rawIds, ?int $deletedBy): ContentEntryBulkResult
    {
        $rows = $this->rowsForBulk($contentTypeId, $rawIds);
        $applied = 0;
        $skipped = 0;
        $reasons = [];
        $appliedIds = [];

        foreach ($rows['missing'] as $id) {
            $skipped++;
            $reasons[] = 'Entry #' . $id . ' not found.';
        }

        foreach ($rows['rows'] as $row) {
            $entryId = (int) $row['id'];
            if ($this->entries->trash($entryId, $deletedBy)) {
                $applied++;
                $appliedIds[] = $entryId;
            } else {
                $skipped++;
                $reasons[] = '#' . $entryId . ': could not move to trash.';
            }
        }

        return new ContentEntryBulkResult($applied, $skipped, $reasons, $appliedIds);
    }

    /**
     * @param list<int|string> $rawIds
     * @param list<int|string> $rawTermIds
     */
    public function assignTaxonomy(
        int $contentTypeId,
        array $rawIds,
        int $taxonomyId,
        array $rawTermIds,
        TaxonomyRepository $taxonomies,
        TaxonomyTermRepository $terms,
        string $mode = 'merge',
    ): ContentEntryBulkResult {
        $tax = $taxonomies->findById($taxonomyId);
        if ($tax === null || $tax->contentTypeId !== $contentTypeId) {
            return new ContentEntryBulkResult(0, count(self::normalizeIds($rawIds)), ['Invalid taxonomy for this content type.']);
        }

        $allowed = [];
        foreach ($terms->forTaxonomyOrdered($taxonomyId) as $term) {
            $allowed[$term->id] = true;
        }

        $termIds = [];
        foreach (self::normalizeIds($rawTermIds) as $tid) {
            if (!isset($allowed[$tid])) {
                return new ContentEntryBulkResult(0, count(self::normalizeIds($rawIds)), ['One or more terms are invalid for this taxonomy.']);
            }
            $termIds[] = $tid;
        }

        if ($termIds === []) {
            return new ContentEntryBulkResult(0, count(self::normalizeIds($rawIds)), ['Select at least one term.']);
        }

        $mode = $mode === 'replace' ? 'replace' : 'merge';
        $rows = $this->rowsForBulk($contentTypeId, $rawIds);
        $applied = 0;
        $skipped = 0;
        $reasons = [];
        $appliedIds = [];

        foreach ($rows['missing'] as $id) {
            $skipped++;
            $reasons[] = 'Entry #' . $id . ' not found.';
        }

        foreach ($rows['rows'] as $row) {
            $entryId = (int) $row['id'];
            $byTaxonomy = $this->entryTaxonomy->termIdsByTaxonomyForEntry($entryId);
            if ($mode === 'replace') {
                $byTaxonomy[$taxonomyId] = $termIds;
            } else {
                $existing = $byTaxonomy[$taxonomyId] ?? [];
                $byTaxonomy[$taxonomyId] = array_values(array_unique(array_merge($existing, $termIds)));
            }

            $all = [];
            foreach ($byTaxonomy as $bucket) {
                foreach ($bucket as $tid) {
                    $all[] = (int) $tid;
                }
            }
            EntryTaxonomySync::sync($entryId, $all, $this->entryTaxonomy);
            $applied++;
            $appliedIds[] = $entryId;
        }

        return new ContentEntryBulkResult($applied, $skipped, $reasons, $appliedIds);
    }

    /**
     * @param list<int|string> $rawIds
     * @return list<int>
     */
    public static function normalizeIds(array $rawIds): array
    {
        $out = [];
        foreach ($rawIds as $raw) {
            $id = (int) $raw;
            if ($id > 0) {
                $out[$id] = $id;
            }
        }

        return array_values($out);
    }

    /**
     * @param list<int|string> $rawIds
     * @return array{rows: list<array<string, mixed>>, missing: list<int>}
     */
    private function rowsForBulk(int $contentTypeId, array $rawIds): array
    {
        $ids = self::normalizeIds($rawIds);
        if ($ids === []) {
            return ['rows' => [], 'missing' => []];
        }

        $fetched = $this->entries->fetchRowsByIds($ids);
        $rows = [];
        $missing = [];
        foreach ($ids as $id) {
            $row = $fetched[$id] ?? null;
            if ($row === null || (int) ($row['content_type_id'] ?? 0) !== $contentTypeId) {
                $missing[] = $id;

                continue;
            }
            $rows[] = $row;
        }

        return ['rows' => $rows, 'missing' => $missing];
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
