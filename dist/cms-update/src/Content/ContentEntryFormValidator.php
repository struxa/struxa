<?php

declare(strict_types=1);

namespace App\Content;

use App\Media\MediaRepository;
use DateTimeImmutable;
use DateTimeZone;

final class ContentEntryFormValidator
{
    public function __construct(
        private readonly ContentFieldValueNormalizer $fieldNormalizer = new ContentFieldValueNormalizer()
    ) {
    }

    /**
     * @param list<ContentField> $fields
     * @param array<string, mixed> $body
     * @return array{
     *   errors: array<string, string>,
     *   values: array{
     *     title: string,
     *     slug: string,
     *     status: string,
     *     featured_image_id: ?int,
     *     seo_title: ?string,
     *     seo_description: ?string,
     *     published_at: ?string,
     *     scheduled_publish_at: ?string,
     *     scheduled_unpublish_at: ?string,
     *     custom: array<int, string|null>
     *   }
     * }
     */
    public function validate(
        array $body,
        ContentType $type,
        array $fields,
        ContentEntryRepository $entryRepo,
        ContentTypeRepository $typeRepo,
        MediaRepository $mediaRepo,
        ?int $exceptEntryId = null
    ): array {
        $errors = [];

        $title = $this->str($body, 'title');
        if ($title === '') {
            $errors['title'] = 'Title is required.';
        } elseif (mb_strlen($title) > 255) {
            $errors['title'] = 'Title is too long.';
        }

        $slug = $this->str($body, 'slug');
        if ($slug === '') {
            $slug = ContentSlugger::slugify($title);
        }
        $slug = strtolower(trim($slug));
        if (!preg_match('/^[a-z0-9][a-z0-9\-]{0,188}$/', $slug)) {
            $errors['slug'] = 'Use a URL-safe slug (lowercase letters, numbers, hyphens).';
        } elseif ($entryRepo->slugExists($type->id, $slug, $exceptEntryId)) {
            $errors['slug'] = 'That slug is already used for this content type.';
        }

        $status = $this->str($body, 'status');
        $allowedStatus = ['draft', 'in_review', 'approved', 'published', 'archived'];
        if (!in_array($status, $allowedStatus, true)) {
            $errors['status'] = 'Invalid workflow status.';
        }

        $featuredImageId = null;
        if ($type->supportsFeaturedImage) {
            $fi = $this->str($body, 'featured_image_id');
            if ($fi === '') {
                $featuredImageId = null;
            } elseif (!ctype_digit($fi)) {
                $errors['featured_image_id'] = 'Featured image must be a valid media ID.';
            } else {
                $mid = (int) $fi;
                $m = $mediaRepo->findById($mid);
                if ($m === null || !$m->isImage()) {
                    $errors['featured_image_id'] = 'Choose an image from the media library.';
                } else {
                    $featuredImageId = $mid;
                }
            }
        }

        $seoTitle = null;
        $seoDescription = null;
        if ($type->supportsSeo) {
            $seoTitle = $this->nullableStr($body, 'seo_title');
            if ($seoTitle !== null && mb_strlen($seoTitle) > 255) {
                $errors['seo_title'] = 'SEO title is too long.';
            }
            $seoDescription = $this->nullableStr($body, 'seo_description');
            if ($seoDescription !== null && mb_strlen($seoDescription) > 500) {
                $errors['seo_description'] = 'SEO description is too long.';
            }
        }

        $publishedAt = null;
        $pubRaw = $this->str($body, 'published_at');
        if ($pubRaw !== '') {
            $publishedAt = $this->parsePublishedAt($pubRaw);
            if ($publishedAt === null) {
                $errors['published_at'] = 'Use a valid date/time.';
            }
        }

        $schedPubRaw = $this->str($body, 'scheduled_publish_at');
        $schedPub = $schedPubRaw !== '' ? $this->parsePublishedAt($schedPubRaw) : null;
        if ($schedPubRaw !== '' && $schedPub === null) {
            $errors['scheduled_publish_at'] = 'Use a valid date/time.';
        }

        $schedUnpubRaw = $this->str($body, 'scheduled_unpublish_at');
        $schedUnpub = $schedUnpubRaw !== '' ? $this->parsePublishedAt($schedUnpubRaw) : null;
        if ($schedUnpubRaw !== '' && $schedUnpub === null) {
            $errors['scheduled_unpublish_at'] = 'Use a valid date/time.';
        }

        $now = new DateTimeImmutable('now', new DateTimeZone(date_default_timezone_get()));
        if ($schedPub !== null && !$this->isFutureSql($schedPub, $now)) {
            $errors['scheduled_publish_at'] = 'Schedule publish must be in the future.';
        }
        if ($schedUnpub !== null && !$this->isFutureSql($schedUnpub, $now)) {
            $errors['scheduled_unpublish_at'] = 'Unpublish time must be in the future.';
        }

        if ($status !== 'published') {
            $schedUnpub = null;
        }

        if ($status === 'published' && $schedPub !== null) {
            $errors['scheduled_publish_at'] = 'Clear “Schedule publish” when status is Published (use “Published at” for a delayed go-live), or set status to Approved.';
        }

        if ($schedPub !== null && !in_array($status, ['draft', 'in_review', 'approved'], true)) {
            $errors['scheduled_publish_at'] = 'Schedule publish only applies when status is Draft, In review, or Approved.';
        }

        if ($publishedAt !== null) {
            $ts = strtotime($publishedAt);
            if ($ts !== false && $ts > time() && $status !== 'published') {
                $errors['published_at'] = 'A future “Published at” requires status Published.';
            }
        }

        if ($status === 'published' && $publishedAt === null && $pubRaw === '' && $schedPub === null) {
            $publishedAt = date('Y-m-d H:i:s');
        }

        $customResult = $this->fieldNormalizer->normalizeAll($fields, $body, $mediaRepo);
        foreach ($customResult['errors'] as $k => $msg) {
            $errors[$k] = $msg;
        }
        foreach (ContentEntryRefsGuard::validate(
            $fields,
            $customResult['values'],
            $status,
            $exceptEntryId,
            $entryRepo,
            $typeRepo,
        ) as $k => $msg) {
            $errors[$k] = $msg;
        }

        return [
            'errors' => $errors,
            'values' => [
                'title' => $title,
                'slug' => $slug,
                'status' => $status,
                'featured_image_id' => $featuredImageId,
                'seo_title' => $seoTitle,
                'seo_description' => $seoDescription,
                'published_at' => $publishedAt,
                'scheduled_publish_at' => $schedPub,
                'scheduled_unpublish_at' => $schedUnpub,
                'custom' => $customResult['values'],
            ],
        ];
    }

    private function parsePublishedAt(string $raw): ?string
    {
        $raw = trim(str_replace('T', ' ', $raw));
        foreach (['Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d'] as $fmt) {
            $dt = \DateTimeImmutable::createFromFormat($fmt, $raw);
            if ($dt !== false) {
                return $dt->format('Y-m-d H:i:s');
            }
        }

        return null;
    }

    /**
     * @param non-empty-string $sqlDatetime
     */
    private function isFutureSql(string $sqlDatetime, DateTimeImmutable $now): bool
    {
        $t = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $sqlDatetime, $now->getTimezone());
        if ($t === false) {
            return false;
        }

        return $t > $now;
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
