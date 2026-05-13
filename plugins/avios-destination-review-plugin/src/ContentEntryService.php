<?php

declare(strict_types=1);

namespace AviosDestinationReviewPlugin;

use App\Content\ContentEntryRepository;
use App\Content\ContentEntryValueRepository;
use App\Content\ContentTypeRepository;
use PDO;

/**
 * Adapts plugin generations to the core CMS content type system.
 *
 * Every review is stored as a row in cms_content_entries (type slug = "destinations") with
 * its body + iata stored in cms_content_entry_values. The plugin's own adr_reviews table is
 * kept as a thin link table (iata, entry_id, provenance), so the admin datatable can join.
 */
final class ContentEntryService
{
    public const TYPE_SLUG = 'destinations';
    public const FIELD_BODY = 'body';
    public const FIELD_IATA = 'iata';

    public function __construct(
        private readonly PDO $pdo,
        private readonly ContentTypeRepository $types,
        private readonly ContentEntryRepository $entries,
        private readonly ContentEntryValueRepository $values,
    ) {
    }

    public static function fromPdo(PDO $pdo): self
    {
        return new self(
            $pdo,
            new ContentTypeRepository($pdo),
            new ContentEntryRepository($pdo),
            new ContentEntryValueRepository($pdo),
        );
    }

    public function isReady(): bool
    {
        return $this->types->findBySlug(self::TYPE_SLUG) !== null
            && $this->fieldId(self::FIELD_BODY) !== null;
    }

    public function typeId(): ?int
    {
        $t = $this->types->findBySlug(self::TYPE_SLUG);

        return $t?->id;
    }

    /**
     * Returns content_field.id for a given field_key on the destinations type, or null.
     */
    public function fieldId(string $fieldKey): ?int
    {
        $typeId = $this->typeId();
        if ($typeId === null) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT id FROM cms_content_fields WHERE content_type_id = ? AND field_key = ? LIMIT 1'
        );
        $stmt->execute([$typeId, $fieldKey]);
        $v = $stmt->fetchColumn();

        return $v === false ? null : (int) $v;
    }

    /**
     * Returns the existing content entry id for an IATA (via the adr_reviews link table), if any.
     */
    public function entryIdForIata(string $iata): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT entry_id FROM adr_reviews WHERE iata = ? AND entry_id IS NOT NULL LIMIT 1'
        );
        $stmt->execute([strtoupper($iata)]);
        $v = $stmt->fetchColumn();

        return $v === false || $v === null ? null : (int) $v;
    }

    /**
     * Create or update the content entry + values for a destination.
     *
     * @param array{
     *   meta_title:string,
     *   meta_description:string,
     *   content_html:string,
     *   featured_image_id?:?int
     * } $review
     */
    public function upsertEntry(string $iata, string $destination, string $slug, array $review): int
    {
        $typeId = $this->typeId();
        if ($typeId === null) {
            throw new \RuntimeException('Destinations content type is missing. Run migration 002_adr_content_type.sql.');
        }
        $bodyFieldId = $this->fieldId(self::FIELD_BODY);
        $iataFieldId = $this->fieldId(self::FIELD_IATA);
        if ($bodyFieldId === null) {
            throw new \RuntimeException('The "body" content field is missing on the destinations type.');
        }

        $iata = strtoupper($iata);
        $title = $destination;
        $seoTitle = $review['meta_title'] !== '' ? $review['meta_title'] : null;
        $seoDesc = $review['meta_description'] !== '' ? $review['meta_description'] : null;
        $publishedAt = date('Y-m-d H:i:s');

        $existingId = $this->entryIdForIata($iata);
        if ($existingId === null) {
            // Fallback: an entry may already exist with this slug from a manual import.
            $existingId = $this->findEntryIdBySlug($typeId, $slug);
        }

        // When the caller hands us a freshly generated featured image, prefer it. Otherwise
        // preserve whatever image is already attached to the entry (so re-generating text
        // without a new image doesn't strip the existing one).
        $featuredImageId = $review['featured_image_id'] ?? null;
        if ($featuredImageId === null && $existingId !== null) {
            $featuredImageId = $this->currentFeaturedImageId($existingId);
        }

        if ($existingId !== null) {
            $this->entries->update(
                id: $existingId,
                title: $title,
                slug: $slug,
                status: 'published',
                featuredImageId: $featuredImageId,
                seoTitle: $seoTitle,
                seoDescription: $seoDesc,
                canonicalUrl: null,
                seoNoindex: false,
                ogTitle: null,
                ogDescription: null,
                ogImageId: $featuredImageId,
                twitterTitle: null,
                twitterDescription: null,
                twitterImageId: $featuredImageId,
                schemaJson: null,
                publishedAt: $publishedAt
            );
            $entryId = $existingId;
        } else {
            $entryId = $this->entries->insert(
                contentTypeId: $typeId,
                title: $title,
                slug: $slug,
                status: 'published',
                featuredImageId: $featuredImageId,
                seoTitle: $seoTitle,
                seoDescription: $seoDesc,
                canonicalUrl: null,
                seoNoindex: false,
                ogTitle: null,
                ogDescription: null,
                ogImageId: $featuredImageId,
                twitterTitle: null,
                twitterDescription: null,
                twitterImageId: $featuredImageId,
                schemaJson: null,
                publishedAt: $publishedAt,
                createdBy: null
            );
        }

        $this->values->upsert($entryId, $bodyFieldId, $review['content_html']);
        if ($iataFieldId !== null) {
            $this->values->upsert($entryId, $iataFieldId, $iata);
        }

        return $entryId;
    }

    /**
     * Joined view used by the admin datatable — pulls plugin metadata together with the
     * canonical entry fields (title, slug, status, published_at).
     *
     * @return list<array{
     *   id:int, iata:string, entry_id:?int,
     *   entry_title:?string, entry_slug:?string, entry_status:?string,
     *   entry_seo_title:?string, entry_seo_description:?string, entry_published_at:?string,
     *   model_used:?string, prompt_used:?string,
     *   updated_at:string
     * }>
     */
    public function listJoined(): array
    {
        $sql = 'SELECT a.id, a.iata, a.entry_id, a.model_used, a.prompt_used,
                       a.updated_at,
                       e.title       AS entry_title,
                       e.slug        AS entry_slug,
                       e.status      AS entry_status,
                       e.seo_title   AS entry_seo_title,
                       e.seo_description AS entry_seo_description,
                       e.published_at AS entry_published_at,
                       e.featured_image_id AS entry_featured_image_id,
                       m.path        AS entry_featured_image_path
                FROM adr_reviews a
                LEFT JOIN cms_content_entries e ON e.id = a.entry_id
                LEFT JOIN cms_media m ON m.id = e.featured_image_id
                ORDER BY a.iata';
        $stmt = $this->pdo->query($sql);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return array<string, array{
     *   link_id:int, entry_id:int, slug:string, title:string, status:string,
     *   seo_title:?string, seo_description:?string,
     *   published_at:?string, updated_at:string,
     *   model_used:?string
     * }> keyed by IATA
     */
    public function indexByIata(): array
    {
        $out = [];
        foreach ($this->listJoined() as $r) {
            if ((int) ($r['entry_id'] ?? 0) > 0) {
                $out[(string) $r['iata']] = [
                    'link_id' => (int) $r['id'],
                    'entry_id' => (int) $r['entry_id'],
                    'slug' => (string) ($r['entry_slug'] ?? ''),
                    'title' => (string) ($r['entry_title'] ?? ''),
                    'status' => (string) ($r['entry_status'] ?? ''),
                    'seo_title' => $r['entry_seo_title'] !== null ? (string) $r['entry_seo_title'] : null,
                    'seo_description' => $r['entry_seo_description'] !== null ? (string) $r['entry_seo_description'] : null,
                    'published_at' => $r['entry_published_at'] !== null ? (string) $r['entry_published_at'] : null,
                    'updated_at' => (string) ($r['updated_at'] ?? ''),
                    'model_used' => $r['model_used'] !== null ? (string) $r['model_used'] : null,
                    'featured_image_id' => $r['entry_featured_image_id'] !== null ? (int) $r['entry_featured_image_id'] : null,
                    'featured_image_path' => $r['entry_featured_image_path'] !== null ? (string) $r['entry_featured_image_path'] : null,
                ];
            }
        }

        return $out;
    }

    /**
     * Delete the content entry (and its values, via FK cascade) AND the plugin's link row.
     */
    public function deleteForLink(int $adrReviewId): void
    {
        $stmt = $this->pdo->prepare('SELECT entry_id FROM adr_reviews WHERE id = ? LIMIT 1');
        $stmt->execute([$adrReviewId]);
        $entryId = $stmt->fetchColumn();
        if (is_numeric($entryId) && (int) $entryId > 0) {
            $this->values->deleteForEntry((int) $entryId);
            $this->entries->delete((int) $entryId);
        }
        $stmt = $this->pdo->prepare('DELETE FROM adr_reviews WHERE id = ?');
        $stmt->execute([$adrReviewId]);
    }

    private function findEntryIdBySlug(int $typeId, string $slug): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM cms_content_entries WHERE content_type_id = ? AND slug = ? LIMIT 1'
        );
        $stmt->execute([$typeId, $slug]);
        $v = $stmt->fetchColumn();

        return $v === false || $v === null ? null : (int) $v;
    }

    private function currentFeaturedImageId(int $entryId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT featured_image_id FROM cms_content_entries WHERE id = ? LIMIT 1');
        $stmt->execute([$entryId]);
        $v = $stmt->fetchColumn();

        return $v === false || $v === null ? null : (int) $v;
    }

    /**
     * Attach (or detach) a featured image on an existing entry without touching anything else.
     * Also mirrors the value to og_image_id / twitter_image_id so social previews stay in sync,
     * matching the behaviour of upsertEntry().
     */
    public function setFeaturedImage(int $entryId, ?int $mediaId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE cms_content_entries
                SET featured_image_id = ?, og_image_id = ?, twitter_image_id = ?,
                    updated_at = CURRENT_TIMESTAMP
              WHERE id = ?'
        );
        $stmt->execute([$mediaId, $mediaId, $mediaId, $entryId]);
    }

    /**
     * @return array{iata:string, destination:string, entry_id:int, slug:string}|null
     */
    public function findLink(int $adrReviewId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.iata, a.destination, a.entry_id, e.slug
               FROM adr_reviews a
               LEFT JOIN cms_content_entries e ON e.id = a.entry_id
              WHERE a.id = ?
              LIMIT 1'
        );
        $stmt->execute([$adrReviewId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || (int) ($row['entry_id'] ?? 0) <= 0) {
            return null;
        }

        return [
            'iata' => (string) $row['iata'],
            'destination' => (string) $row['destination'],
            'entry_id' => (int) $row['entry_id'],
            'slug' => (string) ($row['slug'] ?? ''),
        ];
    }
}
