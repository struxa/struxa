<?php

declare(strict_types=1);

namespace App\Page;

final class PageValidator
{
    private const SLUG_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    /**
     * @param array<string, mixed> $input
     * @return array{
     *   errors: array<string, string>,
     *   values: array{
     *     title: string,
     *     slug: string,
     *     seo_title: string,
     *     seo_description: string,
     *     tags: string,
     *     content: string,
     *     status: string,
     *     featured_image_id: int|null,
     *     featured_image_id_typed: string
     *   }
     * }
     */
    public function validate(array $input): array
    {
        $errors = [];
        $title = isset($input['title']) ? trim((string) $input['title']) : '';
        $slugRaw = isset($input['slug']) ? trim((string) $input['slug']) : '';
        $content = isset($input['content']) ? (string) $input['content'] : '';
        $statusRaw = isset($input['status']) ? trim((string) $input['status']) : '';
        $statusNorm = $statusRaw === '' ? 'draft' : $statusRaw;

        $seoTitle = isset($input['seo_title']) ? trim(strip_tags((string) $input['seo_title'])) : '';
        $seoDesc = isset($input['seo_description']) ? trim(strip_tags((string) $input['seo_description'])) : '';
        $tagsRaw = isset($input['tags']) ? trim((string) $input['tags']) : '';
        $fiRaw = isset($input['featured_image_id']) ? trim((string) $input['featured_image_id']) : '';
        $featuredImageId = null;
        if ($fiRaw !== '') {
            if (!preg_match('/^\d+$/', $fiRaw)) {
                $errors['featured_image_id'] = 'Featured image must be a media library ID or blank.';
            } else {
                $featuredImageId = (int) $fiRaw;
            }
        }

        if ($title === '') {
            $errors['title'] = 'Title is required.';
        } elseif (strlen($title) > 255) {
            $errors['title'] = 'Title must be at most 255 characters.';
        }

        if (strlen($seoTitle) > 255) {
            $errors['seo_title'] = 'SEO title must be at most 255 characters.';
        }

        if (strlen($seoDesc) > 500) {
            $errors['seo_description'] = 'SEO description must be at most 500 characters.';
        }

        if (strlen($tagsRaw) > PageTagParser::MAX_INPUT_LEN) {
            $errors['tags'] = 'Tags are too long; shorten the list.';
        }

        $allowed = ['draft', 'in_review', 'approved', 'published', 'archived'];
        if (!in_array($statusNorm, $allowed, true)) {
            $errors['status'] = 'Invalid workflow status.';
        }

        $slug = $slugRaw;
        if ($slug !== '' && !preg_match(self::SLUG_PATTERN, $slug)) {
            $errors['slug'] = 'Slug may only contain lowercase letters, numbers, and hyphens.';
        }

        return [
            'errors' => $errors,
            'values' => [
                'title' => $title,
                'slug' => $slug,
                'seo_title' => $seoTitle,
                'seo_description' => $seoDesc,
                'tags' => $tagsRaw,
                'content' => $content,
                'status' => $statusRaw === '' ? 'draft' : $statusRaw,
                'featured_image_id' => $featuredImageId,
                'featured_image_id_typed' => $fiRaw,
            ],
        ];
    }

    /**
     * Used after HTML sanitization: true if there is readable text or a non-text block (image, table, etc.).
     */
    public static function sanitizedBodyHasMeaningfulContent(string $html): bool
    {
        $decoded = html_entity_decode($html, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        $plain = trim(str_replace(["\xc2\xa0", '&nbsp;'], ' ', strip_tags($decoded)));
        if ($plain !== '') {
            return true;
        }

        return (bool) preg_match('/<(img|hr|table|figure|iframe|video|audio)\b/i', $html);
    }
}
