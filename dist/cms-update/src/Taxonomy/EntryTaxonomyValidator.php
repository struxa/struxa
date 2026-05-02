<?php

declare(strict_types=1);

namespace App\Taxonomy;

/**
 * Validates taxonomy term assignments on content entry forms.
 */
final class EntryTaxonomyValidator
{
    /**
     * Repopulate checkboxes after a failed save (no validation).
     *
     * @return array<int, list<int>> taxonomy_id => term ids
     */
    public static function selectionsFromBody(array $body): array
    {
        $raw = $body['taxonomy_terms'] ?? [];
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $k => $bucket) {
            $taxId = is_numeric($k) ? (int) $k : 0;
            if ($taxId < 1) {
                continue;
            }
            if (!is_array($bucket)) {
                $bucket = $bucket !== '' && $bucket !== null ? [$bucket] : [];
            }
            foreach ($bucket as $idRaw) {
                if ($idRaw === '' || $idRaw === null) {
                    continue;
                }
                if (is_numeric($idRaw)) {
                    $out[$taxId][] = (int) $idRaw;
                }
            }
        }

        return $out;
    }

    /**
     * @param list<Taxonomy> $taxonomies
     * @param array<string, mixed> $body
     * @return array{errors: array<string, string>, term_ids: list<int>}
     */
    public function validate(array $body, array $taxonomies, TaxonomyTermRepository $termRepo): array
    {
        $errors = [];
        $raw = $body['taxonomy_terms'] ?? [];
        if (!is_array($raw)) {
            $raw = [];
        }

        $allowedByTaxonomy = [];
        foreach ($taxonomies as $tax) {
            $allowedByTaxonomy[$tax->id] = [];
            foreach ($termRepo->forTaxonomyOrdered($tax->id) as $t) {
                $allowedByTaxonomy[$tax->id][$t->id] = true;
            }
        }

        $selected = [];
        foreach ($taxonomies as $tax) {
            $key = $tax->id;
            $bucket = $raw[$key] ?? $raw[(string) $key] ?? [];
            if (!is_array($bucket)) {
                $bucket = $bucket !== '' && $bucket !== null ? [$bucket] : [];
            }
            $bucketOk = [];
            foreach ($bucket as $idRaw) {
                if ($idRaw === '' || $idRaw === null) {
                    continue;
                }
                $tid = is_numeric($idRaw) ? (int) $idRaw : 0;
                if ($tid < 1) {
                    $errors['taxonomy_' . $tax->id] = 'Invalid term selection.';

                    continue 2;
                }
                if (!isset($allowedByTaxonomy[$tax->id][$tid])) {
                    $errors['taxonomy_' . $tax->id] = 'One or more terms are not valid for this taxonomy.';

                    continue 2;
                }
                $bucketOk[] = $tid;
            }
            $selected = array_merge($selected, array_values(array_unique($bucketOk)));
        }

        return ['errors' => $errors, 'term_ids' => $selected];
    }
}
