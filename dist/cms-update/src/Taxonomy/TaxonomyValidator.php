<?php

declare(strict_types=1);

namespace App\Taxonomy;

final class TaxonomyValidator
{
    /**
     * @param array<string, mixed> $body
     * @return array{errors: array<string, string>, values: array<string, mixed>}
     */
    public function validate(array $body, int $contentTypeId, ?int $exceptId, TaxonomyRepository $repo): array
    {
        $errors = [];

        $name = $this->str($body, 'name');
        if ($name === '') {
            $errors['name'] = 'Name is required.';
        } elseif (mb_strlen($name) > 160) {
            $errors['name'] = 'Name is too long.';
        }

        $slug = strtolower(trim($this->str($body, 'slug')));
        if ($slug === '') {
            $slug = TaxonomyTermSlugger::slugify($name);
        }
        if (!preg_match('/^[a-z][a-z0-9\-]{0,62}$/', $slug)) {
            $errors['slug'] = 'Use a lowercase machine name (letters, numbers, hyphens).';
        } elseif ($repo->slugExists($contentTypeId, $slug, $exceptId)) {
            $errors['slug'] = 'That slug is already used for this content type.';
        }

        $description = $this->nullableStr($body, 'description');
        $type = $this->str($body, 'taxonomy_type');
        if (!TaxonomyType::isValid($type)) {
            $errors['taxonomy_type'] = 'Pick a valid taxonomy type.';
        }

        $hier = !empty($body['is_hierarchical']);

        return [
            'errors' => $errors,
            'values' => [
                'name' => $name,
                'slug' => $slug,
                'description' => $description,
                'taxonomy_type' => $type,
                'is_hierarchical' => $hier,
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
