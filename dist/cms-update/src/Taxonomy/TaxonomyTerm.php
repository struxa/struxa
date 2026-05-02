<?php

declare(strict_types=1);

namespace App\Taxonomy;

final class TaxonomyTerm
{
    public function __construct(
        public readonly int $id,
        public readonly int $taxonomyId,
        public readonly string $name,
        public readonly string $slug,
        public readonly ?string $description,
        public readonly ?string $seoTitle,
        public readonly ?string $seoDescription,
        public readonly ?string $canonicalUrl,
        public readonly bool $seoNoindex,
        public readonly ?string $ogTitle,
        public readonly ?string $ogDescription,
        public readonly ?int $ogImageId,
        public readonly ?string $twitterTitle,
        public readonly ?string $twitterDescription,
        public readonly ?int $twitterImageId,
        public readonly ?string $schemaJson,
        public readonly ?int $parentId,
        public readonly int $sortOrder,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $pid = $row['parent_id'] ?? null;
        $og = $row['og_image_id'] ?? null;
        $tw = $row['twitter_image_id'] ?? null;
        $sj = $row['schema_json'] ?? null;

        return new self(
            (int) $row['id'],
            (int) $row['taxonomy_id'],
            (string) $row['name'],
            (string) $row['slug'],
            isset($row['description']) && $row['description'] !== '' ? (string) $row['description'] : null,
            isset($row['seo_title']) && $row['seo_title'] !== '' ? (string) $row['seo_title'] : null,
            isset($row['seo_description']) && $row['seo_description'] !== '' ? (string) $row['seo_description'] : null,
            isset($row['canonical_url']) && $row['canonical_url'] !== '' ? (string) $row['canonical_url'] : null,
            ((int) ($row['seo_noindex'] ?? 0)) === 1,
            isset($row['og_title']) && $row['og_title'] !== '' ? (string) $row['og_title'] : null,
            isset($row['og_description']) && $row['og_description'] !== '' ? (string) $row['og_description'] : null,
            $og !== null && $og !== '' ? (int) $og : null,
            isset($row['twitter_title']) && $row['twitter_title'] !== '' ? (string) $row['twitter_title'] : null,
            isset($row['twitter_description']) && $row['twitter_description'] !== '' ? (string) $row['twitter_description'] : null,
            $tw !== null && $tw !== '' ? (int) $tw : null,
            $sj !== null && $sj !== '' ? (string) $sj : null,
            $pid !== null && $pid !== '' ? (int) $pid : null,
            (int) ($row['sort_order'] ?? 0),
            (string) ($row['created_at'] ?? ''),
            (string) ($row['updated_at'] ?? ''),
        );
    }
}
