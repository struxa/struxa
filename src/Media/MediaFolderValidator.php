<?php

declare(strict_types=1);

namespace App\Media;

final class MediaFolderValidator
{
    /**
     * @param array<string, mixed> $body
     * @return array{errors: array<string, string>, values: array<string, mixed>}
     */
    public function validate(array $body, ?int $exceptId, MediaFolderRepository $repo): array
    {
        $errors = [];

        $name = $this->str($body, 'name');
        if ($name === '') {
            $errors['name'] = 'Folder name is required.';
        } elseif (mb_strlen($name) > 160) {
            $errors['name'] = 'Name is too long.';
        }

        $slug = strtolower(trim($this->str($body, 'slug')));
        if ($slug === '') {
            $slug = MediaFolderSlugger::slugify($name);
        }
        if (!preg_match('/^[a-z0-9][a-z0-9\-]{0,188}$/', $slug)) {
            $errors['slug'] = 'Use a URL-safe slug.';
        }

        $parentId = null;
        $parentRaw = $this->str($body, 'parent_id');
        if ($parentRaw !== '' && $parentRaw !== '0') {
            if (!ctype_digit($parentRaw)) {
                $errors['parent_id'] = 'Invalid parent folder.';
            } else {
                $pid = (int) $parentRaw;
                if ($exceptId !== null && $pid === $exceptId) {
                    $errors['parent_id'] = 'A folder cannot be its own parent.';
                } elseif (!$repo->existsId($pid)) {
                    $errors['parent_id'] = 'Parent folder not found.';
                } elseif ($exceptId !== null && $repo->ancestorChainContains($pid, $exceptId)) {
                    $errors['parent_id'] = 'Cannot nest a folder under its own descendant.';
                } else {
                    $parentId = $pid;
                }
            }
        }

        if ($slug !== '' && $errors === [] && $repo->slugExistsAmongSiblings($parentId, $slug, $exceptId)) {
            $errors['slug'] = 'That slug is already used in this folder level.';
        }

        return [
            'errors' => $errors,
            'values' => [
                'name' => $name,
                'slug' => $slug,
                'parent_id' => $parentId,
            ],
        ];
    }

    private function str(array $body, string $key): string
    {
        if (!isset($body[$key]) || !is_string($body[$key])) {
            return '';
        }

        return trim(str_replace(["\0", "\r"], '', $body[$key]));
    }
}
