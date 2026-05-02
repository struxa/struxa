<?php

declare(strict_types=1);

namespace App\Taxonomy;

use PDO;

final class TaxonomyTermRepository
{
    private const TABLE = 'cms_taxonomy_terms';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return list<TaxonomyTerm>
     */
    public function forTaxonomyOrdered(int $taxonomyId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ' . self::TABLE . ' WHERE taxonomy_id = ? ORDER BY sort_order ASC, name ASC'
        );
        $stmt->execute([$taxonomyId]);
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[] = TaxonomyTerm::fromRow($row);
        }

        return $out;
    }

    public function findById(int $id): ?TaxonomyTerm
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ' . self::TABLE . ' WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : TaxonomyTerm::fromRow($row);
    }

    public function findByTaxonomyAndSlug(int $taxonomyId, string $slug): ?TaxonomyTerm
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ' . self::TABLE . ' WHERE taxonomy_id = ? AND slug = ? LIMIT 1'
        );
        $stmt->execute([$taxonomyId, $slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : TaxonomyTerm::fromRow($row);
    }

    public function slugExists(int $taxonomyId, string $slug, ?int $exceptId = null): bool
    {
        if ($exceptId === null) {
            $stmt = $this->pdo->prepare(
                'SELECT 1 FROM ' . self::TABLE . ' WHERE taxonomy_id = ? AND slug = ? LIMIT 1'
            );
            $stmt->execute([$taxonomyId, $slug]);
        } else {
            $stmt = $this->pdo->prepare(
                'SELECT 1 FROM ' . self::TABLE . ' WHERE taxonomy_id = ? AND slug = ? AND id != ? LIMIT 1'
            );
            $stmt->execute([$taxonomyId, $slug, $exceptId]);
        }

        return (bool) $stmt->fetchColumn();
    }

    public function belongsToTaxonomy(int $termId, int $taxonomyId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM ' . self::TABLE . ' WHERE id = ? AND taxonomy_id = ? LIMIT 1'
        );
        $stmt->execute([$termId, $taxonomyId]);

        return (bool) $stmt->fetchColumn();
    }

    public function nextSortOrder(int $taxonomyId): int
    {
        $stmt = $this->pdo->prepare('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM ' . self::TABLE . ' WHERE taxonomy_id = ?');
        $stmt->execute([$taxonomyId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return int new id
     */
    public function insert(
        int $taxonomyId,
        string $name,
        string $slug,
        ?string $description,
        ?int $parentId,
        int $sortOrder,
        ?string $seoTitle = null,
        ?string $seoDescription = null,
        ?string $canonicalUrl = null,
        bool $seoNoindex = false,
        ?string $ogTitle = null,
        ?string $ogDescription = null,
        ?int $ogImageId = null,
        ?string $twitterTitle = null,
        ?string $twitterDescription = null,
        ?int $twitterImageId = null,
        ?string $schemaJson = null,
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . self::TABLE . '
            (taxonomy_id, name, slug, description, parent_id, sort_order,
             seo_title, seo_description, canonical_url, seo_noindex,
             og_title, og_description, og_image_id, twitter_title, twitter_description, twitter_image_id, schema_json)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $taxonomyId,
            $name,
            $slug,
            $description,
            $parentId,
            $sortOrder,
            $seoTitle,
            $seoDescription,
            $canonicalUrl,
            $seoNoindex ? 1 : 0,
            $ogTitle,
            $ogDescription,
            $ogImageId,
            $twitterTitle,
            $twitterDescription,
            $twitterImageId,
            $schemaJson,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function update(
        int $id,
        string $name,
        string $slug,
        ?string $description,
        ?int $parentId,
        int $sortOrder,
        ?string $seoTitle = null,
        ?string $seoDescription = null,
        ?string $canonicalUrl = null,
        bool $seoNoindex = false,
        ?string $ogTitle = null,
        ?string $ogDescription = null,
        ?int $ogImageId = null,
        ?string $twitterTitle = null,
        ?string $twitterDescription = null,
        ?int $twitterImageId = null,
        ?string $schemaJson = null,
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE ' . self::TABLE . ' SET name = ?, slug = ?, description = ?, parent_id = ?, sort_order = ?,
             seo_title = ?, seo_description = ?, canonical_url = ?, seo_noindex = ?,
             og_title = ?, og_description = ?, og_image_id = ?, twitter_title = ?, twitter_description = ?, twitter_image_id = ?, schema_json = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $name,
            $slug,
            $description,
            $parentId,
            $sortOrder,
            $seoTitle,
            $seoDescription,
            $canonicalUrl,
            $seoNoindex ? 1 : 0,
            $ogTitle,
            $ogDescription,
            $ogImageId,
            $twitterTitle,
            $twitterDescription,
            $twitterImageId,
            $schemaJson,
            $id,
        ]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM ' . self::TABLE . ' WHERE id = ?');
        $stmt->execute([$id]);
    }

    /**
     * True if $needleId appears on the parent chain starting at $startId (inclusive), within guard depth.
     * Used to block assigning a parent that would create a cycle (child under its own descendant).
     */
    public function ancestorChainContains(int $startId, int $needleId): bool
    {
        $seen = [];
        $current = $this->findById($startId);
        $guard = 0;
        while ($current !== null && $guard < 64) {
            if (isset($seen[$current->id])) {
                return false;
            }
            $seen[$current->id] = true;
            if ($current->id === $needleId) {
                return true;
            }
            if ($current->parentId === null) {
                break;
            }
            $current = $this->findById($current->parentId);
            ++$guard;
        }

        return false;
    }
}
