<?php

declare(strict_types=1);

namespace App\Section;

/**
 * Persistence adapter for block builder POST actions (pages and content entries).
 */
interface SectionStoreInterface
{
    /**
     * @return list<object{id: int, sectionKey: string, data: array<string, mixed>, options: array<string, mixed>, sortOrder: int}>
     */
    public function list(int $subjectId): array;

    public function findById(int $id): ?object;

    public function belongs(int $sectionId, int $subjectId): bool;

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $options
     */
    public function insert(int $subjectId, int $sortOrder, string $sectionKey, array $data, array $options): int;

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $options
     */
    public function update(int $id, int $sortOrder, string $sectionKey, array $data, array $options): void;

    public function delete(int $id): void;

    /** @param list<int> $idsInOrder */
    public function reorder(int $subjectId, array $idsInOrder): void;

    public function nextSortOrder(int $subjectId): int;
}
