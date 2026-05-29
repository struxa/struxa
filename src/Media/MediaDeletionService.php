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

    public function delete(int $id): void
    {
        $media = $this->repository->findById($id);
        if ($media === null) {
            return;
        }

        $webPath = $media->path;
        MediaStorage::unlinkManagedFile($this->projectRoot, $webPath);
        $this->repository->deleteById($id);
    }

    /**
     * @param list<int|string> $ids
     */
    public function deleteMany(array $ids): int
    {
        $deleted = 0;
        foreach ($ids as $raw) {
            $id = (int) $raw;
            if ($id < 1) {
                continue;
            }
            $before = $this->repository->findById($id);
            if ($before === null) {
                continue;
            }
            $this->delete($id);
            $deleted++;
        }

        return $deleted;
    }
}
