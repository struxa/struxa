<?php

declare(strict_types=1);

namespace App\Content;

use App\Media\MediaRepository;

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
     *     custom: array<int, string|null>
     *   }
     * }
     */
    public function validate(
        array $body,
        ContentType $type,
        array $fields,
        ContentEntryRepository $entryRepo,
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
        } elseif ($status === 'published') {
            $publishedAt = date('Y-m-d H:i:s');
        }

        $customResult = $this->fieldNormalizer->normalizeAll($fields, $body, $mediaRepo);
        foreach ($customResult['errors'] as $k => $msg) {
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
