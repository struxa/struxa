<?php

declare(strict_types=1);

namespace App\Section;

final class PageSectionStore implements SectionStoreInterface
{
    public function __construct(private readonly PageSectionRepository $repo)
    {
    }

    public function list(int $subjectId): array
    {
        return $this->repo->listForPage($subjectId);
    }

    public function findById(int $id): ?object
    {
        return $this->repo->findById($id);
    }

    public function belongs(int $sectionId, int $subjectId): bool
    {
        return $this->repo->belongsToPage($sectionId, $subjectId);
    }

    public function insert(int $subjectId, int $sortOrder, string $sectionKey, array $data, array $options): int
    {
        return $this->repo->insert($subjectId, $sortOrder, $sectionKey, $data, $options);
    }

    public function update(int $id, int $sortOrder, string $sectionKey, array $data, array $options): void
    {
        $this->repo->update($id, $sortOrder, $sectionKey, $data, $options);
    }

    public function delete(int $id): void
    {
        $this->repo->delete($id);
    }

    public function reorder(int $subjectId, array $idsInOrder): void
    {
        $this->repo->reorderForPage($subjectId, $idsInOrder);
    }

    public function nextSortOrder(int $subjectId): int
    {
        return $this->repo->nextSortOrder($subjectId);
    }
}
