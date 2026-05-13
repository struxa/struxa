<?php

declare(strict_types=1);

namespace AviosDestinationReviewPlugin;

use PDO;

/**
 * Thin PDO wrapper around adr_reviews.
 *
 * @phpstan-type AdrReviewRow array{
 *   id:int, iata:string, destination:string, slug:string,
 *   meta_title:?string, meta_description:?string,
 *   content_html:string, model_used:?string, prompt_used:?string,
 *   created_at:string, updated_at:string
 * }
 */
final class ReviewRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function tableExists(): bool
    {
        try {
            $this->pdo->query('SELECT 1 FROM adr_reviews LIMIT 1');

            return true;
        } catch (\PDOException) {
            return false;
        }
    }

    /**
     * @return list<AdrReviewRow>
     */
    public function all(): array
    {
        if (!$this->tableExists()) {
            return [];
        }
        $stmt = $this->pdo->query('SELECT * FROM adr_reviews ORDER BY destination ASC');

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM adr_reviews WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function findByIata(string $iata): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM adr_reviews WHERE iata = ? LIMIT 1');
        $stmt->execute([strtoupper($iata)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function findBySlug(string $slug): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM adr_reviews WHERE slug = ? LIMIT 1');
        $stmt->execute([strtolower(trim($slug))]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * Upsert by IATA — generating a review twice for the same destination overwrites the prior copy.
     */
    public function upsert(
        string $iata,
        string $destination,
        string $metaTitle,
        string $metaDescription,
        string $contentHtml,
        string $modelUsed,
        string $promptUsed
    ): int {
        $iata = strtoupper($iata);
        $slug = $this->slugify($destination, $iata);

        $stmt = $this->pdo->prepare(
            'INSERT INTO adr_reviews (iata, destination, slug, meta_title, meta_description, content_html, model_used, prompt_used)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                destination = VALUES(destination),
                slug = VALUES(slug),
                meta_title = VALUES(meta_title),
                meta_description = VALUES(meta_description),
                content_html = VALUES(content_html),
                model_used = VALUES(model_used),
                prompt_used = VALUES(prompt_used)'
        );
        $stmt->execute([$iata, $destination, $slug, $metaTitle, $metaDescription, $contentHtml, $modelUsed, $promptUsed]);

        $existing = $this->findByIata($iata);

        return (int) ($existing['id'] ?? 0);
    }

    /**
     * Update only the editable fields from the admin modal.
     */
    public function updateContent(int $id, string $metaTitle, string $metaDescription, string $contentHtml): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE adr_reviews SET meta_title = ?, meta_description = ?, content_html = ? WHERE id = ?'
        );
        $stmt->execute([$metaTitle, $metaDescription, $contentHtml, $id]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM adr_reviews WHERE id = ?');
        $stmt->execute([$id]);
    }

    /**
     * Persist the link from a plugin row to its canonical CMS content entry.
     */
    public function linkEntry(int $linkId, int $entryId): void
    {
        $stmt = $this->pdo->prepare('UPDATE adr_reviews SET entry_id = ? WHERE id = ?');
        $stmt->execute([$entryId, $linkId]);
    }

    /**
     * URL-friendly slug. Adds the IATA suffix so it stays unique even when two BA destinations share a name.
     */
    private function slugify(string $destination, string $iata): string
    {
        $base = strtolower(trim($destination));
        $base = preg_replace('/[^a-z0-9]+/i', '-', $base) ?? '';
        $base = trim($base, '-');
        if ($base === '') {
            $base = strtolower($iata);
        }
        $slug = $base . '-' . strtolower($iata);

        return substr($slug, 0, 180);
    }
}
