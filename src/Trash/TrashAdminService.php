<?php

declare(strict_types=1);

namespace App\Trash;

use App\Content\ContentEntryRepository;
use App\Media\MediaDeletionService;
use App\Media\MediaRepository;
use App\Page\PageRepository;

/**
 * Unified trash listing and restore/purge actions.
 */
final class TrashAdminService
{
    public function __construct(
        private readonly PageRepository $pages,
        private readonly ContentEntryRepository $entries,
        private readonly MediaRepository $media,
        private readonly MediaDeletionService $mediaDeletion,
    ) {
    }

    /**
     * @return list<array{
     *   kind: string,
     *   id: int,
     *   title: string,
     *   subtitle: string,
     *   deleted_at: string,
     *   edit_url: string|null,
     *   type_id: int|null
     * }>
     */
    public function listItems(int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));
        $out = [];

        foreach ($this->pages->listTrashed($limit) as $row) {
            $out[] = [
                'kind' => TrashItemKind::PAGE,
                'id' => (int) $row['id'],
                'title' => (string) $row['title'],
                'subtitle' => '/p/' . (string) $row['slug'],
                'deleted_at' => (string) ($row['deleted_at'] ?? ''),
                'edit_url' => null,
                'type_id' => null,
            ];
        }

        foreach ($this->entries->listTrashed($limit) as $row) {
            $out[] = [
                'kind' => TrashItemKind::CONTENT_ENTRY,
                'id' => (int) $row['id'],
                'title' => (string) $row['title'],
                'subtitle' => (string) ($row['type_name'] ?? 'Entry'),
                'deleted_at' => (string) ($row['deleted_at'] ?? ''),
                'edit_url' => null,
                'type_id' => (int) ($row['content_type_id'] ?? 0),
            ];
        }

        foreach ($this->media->listTrashed($limit) as $row) {
            $out[] = [
                'kind' => TrashItemKind::MEDIA,
                'id' => (int) $row['id'],
                'title' => (string) ($row['original_name'] ?? $row['filename'] ?? 'Media file'),
                'subtitle' => (string) ($row['mime_type'] ?? ''),
                'deleted_at' => (string) ($row['deleted_at'] ?? ''),
                'edit_url' => null,
                'type_id' => null,
            ];
        }

        usort($out, static function (array $a, array $b): int {
            return strcmp($b['deleted_at'], $a['deleted_at']);
        });

        return array_slice($out, 0, $limit);
    }

    public function countItems(): int
    {
        return $this->pages->countTrashed() + $this->entries->countTrashed() + $this->media->countTrashed();
    }

    public function restore(string $kind, int $id): bool
    {
        if (!TrashItemKind::isValid($kind) || $id < 1) {
            return false;
        }

        return match ($kind) {
            TrashItemKind::PAGE => $this->pages->restore($id),
            TrashItemKind::CONTENT_ENTRY => $this->entries->restore($id),
            TrashItemKind::MEDIA => $this->media->restore($id),
            default => false,
        };
    }

    public function purge(string $kind, int $id): bool
    {
        if (!TrashItemKind::isValid($kind) || $id < 1) {
            return false;
        }

        return match ($kind) {
            TrashItemKind::PAGE => $this->pages->purge($id),
            TrashItemKind::CONTENT_ENTRY => $this->entries->purge($id),
            TrashItemKind::MEDIA => $this->mediaDeletion->purge($id),
            default => false,
        };
    }
}
