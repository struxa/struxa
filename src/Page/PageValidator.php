<?php

declare(strict_types=1);

namespace App\Page;

use DateTimeImmutable;
use DateTimeZone;

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
     *     featured_image_id_typed: string,
     *     published_at: ?string,
     *     scheduled_publish_at: ?string,
     *     scheduled_unpublish_at: ?string
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

        $publishedAt = self::parseOptionalDatetime(isset($input['published_at']) ? trim((string) $input['published_at']) : '');
        if (isset($input['published_at']) && trim((string) $input['published_at']) !== '' && $publishedAt === null) {
            $errors['published_at'] = 'Use a valid date/time.';
        }

        $schedPub = self::parseOptionalDatetime(isset($input['scheduled_publish_at']) ? trim((string) $input['scheduled_publish_at']) : '');
        if (isset($input['scheduled_publish_at']) && trim((string) $input['scheduled_publish_at']) !== '' && $schedPub === null) {
            $errors['scheduled_publish_at'] = 'Use a valid date/time.';
        }

        $schedUnpub = self::parseOptionalDatetime(isset($input['scheduled_unpublish_at']) ? trim((string) $input['scheduled_unpublish_at']) : '');
        if (isset($input['scheduled_unpublish_at']) && trim((string) $input['scheduled_unpublish_at']) !== '' && $schedUnpub === null) {
            $errors['scheduled_unpublish_at'] = 'Use a valid date/time.';
        }

        $now = new DateTimeImmutable('now', new DateTimeZone(date_default_timezone_get()));
        if ($schedPub !== null && self::isFutureSql($schedPub, $now) === false) {
            $errors['scheduled_publish_at'] = 'Schedule publish must be in the future.';
        }
        if ($schedUnpub !== null && self::isFutureSql($schedUnpub, $now) === false) {
            $errors['scheduled_unpublish_at'] = 'Unpublish time must be in the future.';
        }

        if ($statusNorm !== 'published') {
            $schedUnpub = null;
        }

        if ($statusNorm === 'published' && $schedPub !== null) {
            $errors['scheduled_publish_at'] = 'Clear “Schedule publish” when status is Published (use “Published at” for a delayed go-live), or set status to Approved.';
        }

        if ($publishedAt !== null && $statusNorm === 'published' && self::isFutureSql($publishedAt, $now)) {
            // Embargo: OK
        } elseif ($publishedAt !== null && $statusNorm !== 'published') {
            $errors['published_at'] = 'Future “Published at” requires status Published.';
        }

        if ($schedPub !== null && !in_array($statusNorm, ['draft', 'in_review', 'approved'], true)) {
            $errors['scheduled_publish_at'] = 'Schedule publish only applies when status is Draft, In review, or Approved.';
        }

        if ($publishedAt === null && $statusNorm === 'published' && $schedPub === null) {
            $publishedAt = $now->format('Y-m-d H:i:s');
        }

        $commentsDisabled = !empty($input['comments_disabled']);

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
                'published_at' => $publishedAt,
                'scheduled_publish_at' => $schedPub,
                'scheduled_unpublish_at' => $schedUnpub,
                'comments_disabled' => $commentsDisabled,
            ],
        ];
    }

    /**
     * @param non-empty-string $sqlDatetime "Y-m-d H:i:s"
     */
    private static function isFutureSql(string $sqlDatetime, DateTimeImmutable $now): bool
    {
        $t = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $sqlDatetime, $now->getTimezone());
        if ($t === false) {
            return false;
        }

        return $t > $now;
    }

    private static function parseOptionalDatetime(string $raw): ?string
    {
        $raw = trim(str_replace('T', ' ', $raw));
        if ($raw === '') {
            return null;
        }
        foreach (['Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d'] as $fmt) {
            $dt = DateTimeImmutable::createFromFormat($fmt, $raw);
            if ($dt !== false) {
                return $dt->format('Y-m-d H:i:s');
            }
        }

        return null;
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
