<?php

declare(strict_types=1);

namespace App\Media;

final class MediaDeletionService
{
    public function __construct(
        private readonly MediaRepository $repository,
        private readonly string $projectRoot
    ) {
    }

    /** Move to trash (keeps file on disk). */
    public function trash(int $id, ?int $deletedBy = null): bool
    {
        if ($this->repository->findById($id) === null) {
            return false;
        }

        return $this->repository->trash($id, $deletedBy);
    }

    /** Permanently delete a trashed file. */
    public function purge(int $id): bool
    {
        $path = $this->repository->pathForTrashedId($id);
        if ($path === null) {
            return false;
        }

        MediaStorage::unlinkManagedFile($this->projectRoot, $path);
        $this->repository->deleteById($id);

        return true;
    }

    /**
     * @param list<int|string> $ids
     */
    public function trashMany(array $ids, ?int $deletedBy = null): int
    {
        $count = 0;
        foreach ($ids as $raw) {
            $id = (int) $raw;
            if ($id < 1) {
                continue;
            }
            if ($this->trash($id, $deletedBy)) {
                $count++;
            }
        }

        return $count;
    }

    /** @deprecated Use trash(); purge() from trash screen for permanent removal. */
    public function delete(int $id): void
    {
        $this->trash($id);
    }

    /**
     * @param list<int|string> $ids
     */
    public function deleteMany(array $ids): int
    {
        return $this->trashMany($ids);
    }
}
