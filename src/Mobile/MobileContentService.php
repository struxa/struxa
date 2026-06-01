<?php

declare(strict_types=1);

namespace App\Mobile;

use App\Api\PublicContentApi;
use App\Content\ContentEntryRepository;
use App\Content\ContentEntryValueRepository;
use App\Content\ContentEntryViewPresenter;
use App\Content\ContentFieldRepository;
use App\Content\ContentType;
use App\Content\ContentTypeRepository;
use App\Content\PublicContentIndexPager;
use App\Content\ReservedContentSlugs;
use App\Media\MediaUrlHelper;
use App\Settings\SiteUrlResolver;
use App\Taxonomy\ContentEntryTaxonomyRepository;
use PDO;

/**
 * Published-only content for the Struxa mobile app (no API key).
 */
final class MobileContentService
{
    public const PER_PAGE_DEFAULT = 20;
    public const PER_PAGE_MAX = 30;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array{items: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function listEntries(string $typeSlug, int $page, int $perPage): array
    {
        $this->assertEnabled();
        $type = $this->requirePublicType($typeSlug);
        $page = max(1, $page);
        $perPage = max(1, min(self::PER_PAGE_MAX, $perPage));

        $entries = new ContentEntryRepository($this->pdo);
        $statuses = ['published'];
        $total = $entries->countForContentTypeWithStatuses($type->id, $statuses);
        $totalPages = max(1, (int) ceil(max(0, $total) / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }

        $rows = $entries->listForContentTypePagedWithStatuses($type->id, $statuses, $page, $perPage);
        $siteUrl = SiteUrlResolver::resolve();
        $mediaUrls = new MediaUrlHelper($this->pdo);
        $items = [];
        foreach ($rows as $row) {
            $items[] = self::entryListItem($type, $row, $siteUrl, $mediaUrls);
        }

        return [
            'items' => $items,
            'meta' => [
                'type_slug' => $type->slug,
                'type_name' => $type->name,
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $totalPages,
                'page_items' => PublicContentIndexPager::pageItems($page, $totalPages),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function entryDetail(string $typeSlug, string $entrySlug): array
    {
        $this->assertEnabled();
        $type = $this->requirePublicType($typeSlug);

        $entries = new ContentEntryRepository($this->pdo);
        $entry = $entries->findPublishedByTypeSlug($type->id, $entrySlug);
        if ($entry === null) {
            throw new MobileContentException('not_found', 'Entry not found.');
        }

        $fields = new ContentFieldRepository($this->pdo);
        $values = new ContentEntryValueRepository($this->pdo);
        $mediaUrls = new MediaUrlHelper($this->pdo);
        $entryTaxonomies = new ContentEntryTaxonomyRepository($this->pdo);
        $siteUrl = SiteUrlResolver::resolve();

        $fieldList = $fields->forTypeOrdered($type->id);
        $valueMap = $values->valuesByFieldIdForEntry($entry->id);
        $fieldRows = ContentEntryViewPresenter::buildFieldRows($fieldList, $valueMap, $mediaUrls, $this->pdo, $siteUrl);
        $featuredUrl = PublicContentApi::featuredImageUrlForEntry($entry, $fieldList, $valueMap, $mediaUrls);
        $groups = $entryTaxonomies->termsGroupedForEntry($entry->id);

        return PublicContentApi::entryDetail(
            $type,
            $entry,
            $fieldRows,
            $groups,
            $featuredUrl !== '' ? $featuredUrl : null,
            $siteUrl,
        );
    }

    private function assertEnabled(): void
    {
        if (!MobileSettings::enabled()) {
            throw new MobileContentException(
                'mobile_disabled',
                'Mobile app access is disabled for this site.',
            );
        }
    }

    private function requirePublicType(string $typeSlug): ContentType
    {
        $typeSlug = trim($typeSlug);
        if ($typeSlug === '' || ReservedContentSlugs::isReserved($typeSlug)) {
            throw new MobileContentException('not_found', 'Unknown content type.');
        }

        $type = (new ContentTypeRepository($this->pdo))->findBySlug($typeSlug);
        if ($type === null || !$type->hasPublicRoute) {
            throw new MobileContentException('not_found', 'Unknown content type or type has no public route.');
        }
        if (!MobileSettings::isContentTypeAllowed($type->slug, true)) {
            throw new MobileContentException('not_found', 'This content type is not available in the mobile app.');
        }

        return $type;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function entryListItem(
        ContentType $type,
        array $row,
        string $siteUrl,
        MediaUrlHelper $mediaUrls,
    ): array {
        $summary = PublicContentApi::entrySummary($type, $row, $siteUrl);
        $excerpt = trim((string) ($row['seo_description'] ?? ''));
        $featuredUrl = null;
        if ($type->supportsFeaturedImage) {
            $imageId = (int) ($row['featured_image_id'] ?? 0);
            if ($imageId > 0) {
                $path = $mediaUrls->pathForId($imageId);
                if ($path !== '') {
                    $featuredUrl = MobileBootstrapService::absoluteUrl($siteUrl, $path);
                }
            }
        }

        return $summary + [
            'excerpt' => $excerpt,
            'featured_image_url' => $featuredUrl,
        ];
    }
}
