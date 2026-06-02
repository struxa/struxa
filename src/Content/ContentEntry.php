<?php

declare(strict_types=1);

namespace App\Content;

final class ContentEntry
{
    public function __construct(
        public readonly int $id,
        public readonly int $contentTypeId,
        public readonly string $title,
        public readonly string $slug,
        public readonly string $status,
        public readonly ?int $featuredImageId,
        public readonly ?string $seoTitle,
        public readonly ?string $seoDescription,
        public readonly ?string $focusKeyphrase,
        public readonly ?string $canonicalUrl,
        public readonly bool $seoNoindex,
        public readonly ?string $ogTitle,
        public readonly ?string $ogDescription,
        public readonly ?int $ogImageId,
        public readonly ?string $twitterTitle,
        public readonly ?string $twitterDescription,
        public readonly ?int $twitterImageId,
        public readonly ?string $schemaJson,
        public readonly ?string $publishedAt,
        public readonly ?string $scheduledPublishAt,
        public readonly ?string $scheduledUnpublishAt,
        public readonly bool $membersOnly,
        public readonly ?int $createdBy,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $fid = $row['featured_image_id'] ?? null;
        $cb = $row['created_by'] ?? null;
        $pub = $row['published_at'] ?? null;

        $og = $row['og_image_id'] ?? null;
        $tw = $row['twitter_image_id'] ?? null;
        $sj = $row['schema_json'] ?? null;

        $sp = $row['scheduled_publish_at'] ?? null;
        $su = $row['scheduled_unpublish_at'] ?? null;

        return new self(
            (int) $row['id'],
            (int) $row['content_type_id'],
            (string) $row['title'],
            (string) $row['slug'],
            (string) $row['status'],
            $fid !== null && $fid !== '' ? (int) $fid : null,
            isset($row['seo_title']) && $row['seo_title'] !== '' ? (string) $row['seo_title'] : null,
            isset($row['seo_description']) && $row['seo_description'] !== '' ? (string) $row['seo_description'] : null,
            isset($row['focus_keyphrase']) && $row['focus_keyphrase'] !== '' ? (string) $row['focus_keyphrase'] : null,
            isset($row['canonical_url']) && $row['canonical_url'] !== '' ? (string) $row['canonical_url'] : null,
            ((int) ($row['seo_noindex'] ?? 0)) === 1,
            isset($row['og_title']) && $row['og_title'] !== '' ? (string) $row['og_title'] : null,
            isset($row['og_description']) && $row['og_description'] !== '' ? (string) $row['og_description'] : null,
            $og !== null && $og !== '' ? (int) $og : null,
            isset($row['twitter_title']) && $row['twitter_title'] !== '' ? (string) $row['twitter_title'] : null,
            isset($row['twitter_description']) && $row['twitter_description'] !== '' ? (string) $row['twitter_description'] : null,
            $tw !== null && $tw !== '' ? (int) $tw : null,
            $sj !== null && $sj !== '' ? (string) $sj : null,
            $pub !== null && $pub !== '' ? (string) $pub : null,
            $sp !== null && $sp !== '' ? (string) $sp : null,
            $su !== null && $su !== '' ? (string) $su : null,
            (bool) ((int) ($row['members_only'] ?? 0)),
            $cb !== null && $cb !== '' ? (int) $cb : null,
            (string) ($row['created_at'] ?? ''),
            (string) ($row['updated_at'] ?? ''),
        );
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    public function isPubliclyVisible(): bool
    {
        if ($this->status !== 'published') {
            return false;
        }
        if ($this->publishedAt === null || $this->publishedAt === '') {
            return true;
        }
        $t = strtotime($this->publishedAt);

        return $t !== false && $t <= time();
    }
}
