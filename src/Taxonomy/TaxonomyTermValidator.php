<?php

declare(strict_types=1);

namespace App\Taxonomy;

final class TaxonomyTermValidator
{
    /**
     * @param array<string, mixed> $body
     * @return array{errors: array<string, string>, values: array<string, mixed>}
     */
    public function validate(
        array $body,
        int $taxonomyId,
        ?int $exceptId,
        Taxonomy $taxonomy,
        TaxonomyTermRepository $termRepo
    ): array {
        $errors = [];

        $name = $this->str($body, 'name');
        if ($name === '') {
            $errors['name'] = 'Name is required.';
        } elseif (mb_strlen($name) > 191) {
            $errors['name'] = 'Name is too long.';
        }

        $slug = strtolower(trim($this->str($body, 'slug')));
        if ($slug === '') {
            $slug = TaxonomyTermSlugger::slugify($name);
        }
        if (!preg_match('/^[a-z0-9][a-z0-9\-]{0,188}$/', $slug)) {
            $errors['slug'] = 'Use a URL-safe slug.';
        } elseif ($termRepo->slugExists($taxonomyId, $slug, $exceptId)) {
            $errors['slug'] = 'That slug is already used in this taxonomy.';
        }

        $description = $this->nullableStr($body, 'description');

        $parentId = null;
        $parentRaw = $this->str($body, 'parent_id');
        if ($parentRaw !== '') {
            if (!ctype_digit($parentRaw)) {
                $errors['parent_id'] = 'Invalid parent.';
            } else {
                $pid = (int) $parentRaw;
                if ($taxonomy->isHierarchical) {
                    if ($exceptId !== null && $pid === $exceptId) {
                        $errors['parent_id'] = 'A term cannot be its own parent.';
                    } elseif (!$termRepo->belongsToTaxonomy($pid, $taxonomyId)) {
                        $errors['parent_id'] = 'Parent must belong to this taxonomy.';
                    } elseif ($exceptId !== null && $termRepo->ancestorChainContains($pid, $exceptId)) {
                        $errors['parent_id'] = 'Cannot set parent under this term (would create a loop).';
                    } else {
                        $parentId = $pid;
                    }
                } else {
                    $errors['parent_id'] = 'Parent is only allowed for hierarchical taxonomies.';
                }
            }
        }

        $sortOrder = (int) ($body['sort_order'] ?? 0);
        if ($sortOrder < 0) {
            $errors['sort_order'] = 'Sort order cannot be negative.';
        }

        return [
            'errors' => $errors,
            'values' => [
                'name' => $name,
                'slug' => $slug,
                'description' => $description,
                'parent_id' => $parentId,
                'sort_order' => $sortOrder,
            ],
        ];
    }

    private function str(array $body, string $key): string
    {
        $v = $body[$key] ?? '';

        return trim(is_string($v) ? str_replace("\0", '', $v) : '');
    }

    private function nullableStr(array $body, string $key): ?string
    {
        $v = $this->str($body, $key);

        return $v === '' ? null : $v;
    }
}
