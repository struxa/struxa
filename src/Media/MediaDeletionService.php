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
}
