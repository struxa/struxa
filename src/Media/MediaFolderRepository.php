<?php

declare(strict_types=1);

namespace App\Media;

use PDO;

final class MediaFolderRepository
{
    private const TABLE = 'cms_media_folders';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return list<MediaFolder>
     */
    public function listAllOrdered(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, name, slug, parent_id, sort_order, created_at, updated_at
             FROM ' . self::TABLE . '
             ORDER BY sort_order ASC, name ASC, id ASC'
        );
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[] = MediaFolder::fromRow($row);
        }

        return $out;
    }

    public function findById(int $id): ?MediaFolder
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name, slug, parent_id, sort_order, created_at, updated_at
             FROM ' . self::TABLE . ' WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : MediaFolder::fromRow($row);
    }

    public function existsId(int $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM ' . self::TABLE . ' WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);

        return (bool) $stmt->fetchColumn();
    }

    public function slugExistsAmongSiblings(?int $parentId, string $slug, ?int $exceptId = null): bool
    {
        if ($parentId === null) {
            if ($exceptId === null) {
                $stmt = $this->pdo->prepare(
                    'SELECT 1 FROM ' . self::TABLE . ' WHERE parent_id IS NULL AND slug = ? LIMIT 1'
                );
                $stmt->execute([$slug]);
            } else {
                $stmt = $this->pdo->prepare(
                    'SELECT 1 FROM ' . self::TABLE . ' WHERE parent_id IS NULL AND slug = ? AND id != ? LIMIT 1'
                );
                $stmt->execute([$slug, $exceptId]);
            }
        } elseif ($exceptId === null) {
            $stmt = $this->pdo->prepare(
                'SELECT 1 FROM ' . self::TABLE . ' WHERE parent_id = ? AND slug = ? LIMIT 1'
            );
            $stmt->execute([$parentId, $slug]);
        } else {
            $stmt = $this->pdo->prepare(
                'SELECT 1 FROM ' . self::TABLE . ' WHERE parent_id = ? AND slug = ? AND id != ? LIMIT 1'
            );
            $stmt->execute([$parentId, $slug, $exceptId]);
        }

        return (bool) $stmt->fetchColumn();
    }

    public function nextSortOrder(?int $parentId): int
    {
        if ($parentId === null) {
            $stmt = $this->pdo->query(
                'SELECT COALESCE(MAX(sort_order), -1) + 1 FROM ' . self::TABLE . ' WHERE parent_id IS NULL'
            );
        } else {
            $stmt = $this->pdo->prepare(
                'SELECT COALESCE(MAX(sort_order), -1) + 1 FROM ' . self::TABLE . ' WHERE parent_id = ?'
            );
            $stmt->execute([$parentId]);
        }

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return int new id
     */
    public function insert(string $name, string $slug, ?int $parentId, int $sortOrder): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . self::TABLE . ' (name, slug, parent_id, sort_order) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$name, $slug, $parentId, $sortOrder]);

        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, string $name, string $slug, ?int $parentId, int $sortOrder): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE ' . self::TABLE . ' SET name = ?, slug = ?, parent_id = ?, sort_order = ? WHERE id = ?'
        );
        $stmt->execute([$name, $slug, $parentId, $sortOrder, $id]);
    }

    public function deleteById(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM ' . self::TABLE . ' WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function ancestorChainContains(int $startId, int $needleId): bool
    {
        $seen = [];
        $current = $this->findById($startId);
        $guard = 0;
        while ($current !== null && $guard < 64) {
            if (isset($seen[$current->id])) {
                return false;
            }
            $seen[$current->id] = true;
            if ($current->id === $needleId) {
                return true;
            }
            if ($current->parentId === null) {
                break;
            }
            $current = $this->findById($current->parentId);
            ++$guard;
        }

        return false;
    }

    /**
     * @return list<array{id: int, name: string, slug: string, parent_id: int|null, sort_order: int, file_count: int, children: list<mixed>}>
     */
    public function treeWithCounts(): array
    {
        $folders = $this->listAllOrdered();
        $counts = $this->fileCountsByFolderId();

        /** @var array<int, array{id: int, name: string, slug: string, parent_id: int|null, sort_order: int, file_count: int, children: list<mixed>}> */
        $nodes = [];
        foreach ($folders as $folder) {
            $nodes[$folder->id] = [
                'id' => $folder->id,
                'name' => $folder->name,
                'slug' => $folder->slug,
                'parent_id' => $folder->parentId,
                'sort_order' => $folder->sortOrder,
                'file_count' => $counts[$folder->id] ?? 0,
                'children' => [],
            ];
        }

        /** @var list<array{id: int, name: string, slug: string, parent_id: int|null, sort_order: int, file_count: int, children: list<mixed>}> */
        $roots = [];
        foreach ($nodes as $id => $node) {
            $parentId = $node['parent_id'];
            if ($parentId !== null && isset($nodes[$parentId])) {
                $nodes[$parentId]['children'][] = &$nodes[$id];
            } else {
                $roots[] = &$nodes[$id];
            }
        }
        unset($nodes);

        return $roots;
    }

    /**
     * @return array<int, int>
     */
    public function fileCountsByFolderId(): array
    {
        $stmt = $this->pdo->query(
            'SELECT folder_id, COUNT(*) AS c FROM cms_media WHERE folder_id IS NOT NULL GROUP BY folder_id'
        );
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[(int) $row['folder_id']] = (int) $row['c'];
        }

        return $out;
    }

    public function countUnfiledMedia(): int
    {
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM cms_media WHERE folder_id IS NULL');

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return list<MediaFolder>
     */
    public function breadcrumbChain(int $folderId): array
    {
        $chain = [];
        $current = $this->findById($folderId);
        $guard = 0;
        while ($current !== null && $guard < 64) {
            array_unshift($chain, $current);
            if ($current->parentId === null) {
                break;
            }
            $current = $this->findById($current->parentId);
            ++$guard;
        }

        return $chain;
    }

    /**
     * Flat list for parent & move selects (indented labels).
     *
     * @return list<array{id: int, label: string, depth: int}>
     */
    public function optionsForSelect(?int $excludeId = null): array
    {
        $tree = $this->treeWithCounts();
        $out = [];

        $walk = static function (array $nodes, int $depth) use (&$walk, &$out, $excludeId): void {
            foreach ($nodes as $node) {
                if ($excludeId !== null && (int) $node['id'] === $excludeId) {
                    continue;
                }
                $prefix = $depth > 0 ? str_repeat('— ', $depth) : '';
                $out[] = [
                    'id' => (int) $node['id'],
                    'label' => $prefix . (string) $node['name'],
                    'depth' => $depth,
                ];
                if ($node['children'] !== []) {
                    $walk($node['children'], $depth + 1);
                }
            }
        };

        $walk($tree, 0);

        return $out;
    }
}
