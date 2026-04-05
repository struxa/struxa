<?php

declare(strict_types=1);

namespace App\Page;

final class Page
{
    /**
     * @param list<string> $tags URL slugs stored in tags_json
     */
    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly string $slug,
        public readonly ?string $seoTitle,
        public readonly ?string $seoDescription,
        public readonly array $tags,
        public readonly ?int $featuredImageId,
        public readonly ?string $canonicalUrl,
        public readonly bool $seoNoindex,
        public readonly ?string $ogTitle,
        public readonly ?string $ogDescription,
        public readonly ?int $ogImageId,
        public readonly ?string $twitterTitle,
        public readonly ?string $twitterDescription,
        public readonly ?int $twitterImageId,
        public readonly ?string $schemaJson,
        public readonly string $content,
        public readonly string $status,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $tj = $row['tags_json'] ?? null;
        $tagsJson = $tj !== null && $tj !== '' ? (string) $tj : null;

        $fi = $row['featured_image_id'] ?? null;
        $featuredId = $fi !== null && $fi !== '' ? (int) $fi : null;

        $ni = (int) ($row['seo_noindex'] ?? 0);

        $og = $row['og_image_id'] ?? null;
        $ogId = $og !== null && $og !== '' ? (int) $og : null;
        $tw = $row['twitter_image_id'] ?? null;
        $twId = $tw !== null && $tw !== '' ? (int) $tw : null;

        $sj = $row['schema_json'] ?? null;
        $schema = $sj !== null && $sj !== '' ? (string) $sj : null;

        return new self(
            (int) $row['id'],
            (string) $row['title'],
            (string) $row['slug'],
            isset($row['seo_title']) && $row['seo_title'] !== '' && $row['seo_title'] !== null ? (string) $row['seo_title'] : null,
            isset($row['seo_description']) && $row['seo_description'] !== '' && $row['seo_description'] !== null ? (string) $row['seo_description'] : null,
            PageTagParser::fromJson($tagsJson),
            $featuredId,
            isset($row['canonical_url']) && $row['canonical_url'] !== '' ? (string) $row['canonical_url'] : null,
            $ni === 1,
            isset($row['og_title']) && $row['og_title'] !== '' ? (string) $row['og_title'] : null,
            isset($row['og_description']) && $row['og_description'] !== '' ? (string) $row['og_description'] : null,
            $ogId,
            isset($row['twitter_title']) && $row['twitter_title'] !== '' ? (string) $row['twitter_title'] : null,
            isset($row['twitter_description']) && $row['twitter_description'] !== '' ? (string) $row['twitter_description'] : null,
            $twId,
            $schema,
            (string) $row['content'],
            (string) $row['status'],
            (string) ($row['created_at'] ?? ''),
            (string) ($row['updated_at'] ?? ''),
        );
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    public function metaTitle(?string $siteName): string
    {
        $t = $this->seoTitle !== null && trim($this->seoTitle) !== '' ? trim($this->seoTitle) : $this->title;
        $site = $siteName !== null && $siteName !== '' ? $siteName : 'Site';

        return $t . ' — ' . $site;
    }

    public function metaDescription(): string
    {
        if ($this->seoDescription !== null && trim($this->seoDescription) !== '') {
            return trim($this->seoDescription);
        }

        $plain = trim(preg_replace('/\s+/', ' ', strip_tags($this->content)) ?? '');
        if (strlen($plain) > 160) {
            $plain = substr($plain, 0, 157) . '…';
        }

        return $plain;
    }

    public function tagsEditString(): string
    {
        return PageTagParser::slugsToEditString($this->tags);
    }
}
