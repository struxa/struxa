<?php

declare(strict_types=1);

namespace App\Content;

final class ContentTypeValidator
{
    /**
     * @param array<string, mixed> $body
     * @return array{errors: array<string, string>, values: array<string, mixed>}
     */
    public function validate(array $body, ?int $exceptId = null, ?ContentTypeRepository $repo = null): array
    {
        $errors = [];

        $name = $this->str($body, 'name');
        if ($name === '') {
            $errors['name'] = 'Name is required.';
        } elseif (mb_strlen($name) > 160) {
            $errors['name'] = 'Name is too long.';
        }

        $slug = $this->str($body, 'slug');
        if ($slug === '') {
            $slug = ContentSlugger::slugify($name);
        }
        $slug = strtolower($slug);
        if (!preg_match('/^[a-z][a-z0-9\-]{1,62}$/', $slug)) {
            $errors['slug'] = 'Use a lowercase machine name: letters, numbers, hyphens (2–63 chars).';
        } elseif (ReservedContentSlugs::isReserved($slug)) {
            $errors['slug'] = 'This slug is reserved for system routes.';
        } elseif ($repo !== null && $repo->slugExists($slug, $exceptId)) {
            $errors['slug'] = 'That slug is already in use.';
        }

        $icon = $this->nullableStr($body, 'icon');
        if ($icon !== null && mb_strlen($icon) > 64) {
            $errors['icon'] = 'Icon reference is too long.';
        }

        $description = $this->nullableStr($body, 'description');

        $hasPublic = !empty($body['has_public_route']);
        $supportsSeo = !empty($body['supports_seo']);
        $supportsFeatured = !empty($body['supports_featured_image']);
        $supportsBlockBuilder = !empty($body['supports_block_builder']);

        return [
            'errors' => $errors,
            'values' => [
                'name' => $name,
                'slug' => $slug,
                'icon' => $icon,
                'description' => $description,
                'has_public_route' => $hasPublic,
                'supports_seo' => $supportsSeo,
                'supports_featured_image' => $supportsFeatured,
                'supports_block_builder' => $supportsBlockBuilder,
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
