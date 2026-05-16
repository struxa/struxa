<?php

declare(strict_types=1);

namespace MailingListPlugin;

use App\Content\ReservedContentSlugs;

final class ListValidator
{
    /**
     * @param array<string, mixed> $body
     * @return array{errors: array<string, string>, values: array{name: string, slug: string, description: string, is_active: bool}}
     */
    public static function validate(array $body, ListRepository $repo, ?int $exceptId = null): array
    {
        $errors = [];
        $name = trim(is_string($body['name'] ?? '') ? (string) $body['name'] : '');
        $slugRaw = trim(is_string($body['slug'] ?? '') ? (string) $body['slug'] : '');
        $description = trim(is_string($body['description'] ?? '') ? (string) $body['description'] : '');
        $isActive = !empty($body['is_active']);

        if ($name === '') {
            $errors['name'] = 'Name is required.';
        } elseif (strlen($name) > 191) {
            $errors['name'] = 'Name must be 191 characters or fewer.';
        }

        $slug = $slugRaw !== '' ? strtolower($slugRaw) : Slugger::fromName($name);
        if (!Slugger::isValid($slug)) {
            $errors['slug'] = 'Use lowercase letters, numbers, and hyphens only.';
        } elseif (strlen($slug) > 64) {
            $errors['slug'] = 'Slug must be 64 characters or fewer.';
        } elseif (ReservedContentSlugs::isReserved($slug)) {
            $errors['slug'] = 'That slug is reserved by the CMS or a plugin route.';
        } elseif ($repo->slugTaken($slug, $exceptId)) {
            $errors['slug'] = 'That slug is already used by another list.';
        }

        if (strlen($description) > 65535) {
            $errors['description'] = 'Description is too long.';
        }

        return [
            'errors' => $errors,
            'values' => [
                'name' => $name,
                'slug' => $slug,
                'description' => $description,
                'is_active' => $isActive,
            ],
        ];
    }
}
