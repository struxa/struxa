<?php

declare(strict_types=1);

namespace App\Content\List;

use App\Content\ContentType;
use App\Content\ContentTypeRepository;
use App\Content\PublicContentIndexCardBuilder;
use App\Content\PublicContentIndexPager;
use PDO;

final class ContentListService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ContentListRepository $lists,
        private readonly ContentTypeRepository $types,
        private readonly ContentListQueryRunner $runner,
        private readonly PublicContentIndexCardBuilder $cards,
    ) {
    }

    /**
     * @return array{
     *   list: array<string, mixed>,
     *   type: ContentType,
     *   definition: ContentListDefinition,
     *   rows: list<array<string, mixed>>,
     *   cards: list<array<string, mixed>>,
     *   page: int,
     *   per_page: int,
     *   total: int,
     *   total_pages: int,
     *   page_items: list<int>
     * }|null
     */
    public function runForPublicPage(string $slug, int $page): ?array
    {
        $row = $this->lists->findActiveBySlug($slug);
        if ($row === null || empty($row['expose_public_page'])) {
            return null;
        }

        return $this->runFromRow($row, $page, true);
    }

    public function runForApi(string $slug, int $page, bool $forcePublic): ?array
    {
        $row = $this->lists->findActiveBySlug($slug);
        if ($row === null || empty($row['expose_api'])) {
            return null;
        }

        return $this->runFromRow($row, $page, $forcePublic);
    }

    public function runForTwig(string $slug, int $page): ?array
    {
        $row = $this->lists->findActiveBySlug($slug);
        if ($row === null) {
            return null;
        }

        return $this->runFromRow($row, $page, true);
    }

    public function runForAdminPreview(int $id, int $page): ?array
    {
        $row = $this->lists->findById($id);
        if ($row === null) {
            return null;
        }

        return $this->runFromRow($row, $page, false);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{
     *   list: array<string, mixed>,
     *   type: ContentType,
     *   definition: ContentListDefinition,
     *   rows: list<array<string, mixed>>,
     *   cards: list<array<string, mixed>>,
     *   page: int,
     *   per_page: int,
     *   total: int,
     *   total_pages: int,
     *   page_items: list<int>
     * }|null
     */
    public function runFromRow(array $row, int $page, bool $forcePublic): ?array
    {
        $typeId = (int) ($row['content_type_id'] ?? 0);
        $type = $this->types->findById($typeId);
        if ($type === null) {
            return null;
        }

        $def = ContentListRepository::definitionFromRow($row);
        $forcePublic = $forcePublic || $def->publicOnly;
        $page = max(1, $page);
        $total = $this->runner->count($typeId, $def, $forcePublic);
        $perPage = $def->perPage;
        $totalPages = max(1, (int) ceil(max(0, $total) / max(1, $perPage)));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $entryRows = $this->runner->fetchPage($typeId, $def, $forcePublic, $page);
        $cards = $this->cards->buildForEntries($type, $entryRows);

        return [
            'list' => $row,
            'type' => $type,
            'definition' => $def,
            'rows' => $entryRows,
            'cards' => $cards,
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => $totalPages,
            'page_items' => PublicContentIndexPager::pageItems($page, $totalPages),
        ];
    }
}
