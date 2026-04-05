<?php

declare(strict_types=1);

namespace App\Menu;

use PDO;

final class MenuItemRepository
{
    private const TABLE = 'cms_menu_items';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return list<MenuItem>
     */
    public function forMenuOrdered(int $menuId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, menu_id, label, url, page_id, sort_order, target, css_class, created_at, updated_at
             FROM ' . self::TABLE . ' WHERE menu_id = ? ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute([$menuId]);
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[] = MenuItem::fromRow($row);
        }

        return $out;
    }

    public function findById(int $id): ?MenuItem
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, menu_id, label, url, page_id, sort_order, target, css_class, created_at, updated_at
             FROM ' . self::TABLE . ' WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : MenuItem::fromRow($row);
    }

    /**
     * @return int new id
     */
    public function insert(
        int $menuId,
        string $label,
        string $url,
        ?int $pageId,
        int $sortOrder,
        string $target,
        string $cssClass
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . self::TABLE . ' (menu_id, label, url, page_id, sort_order, target, css_class)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$menuId, $label, $url, $pageId, $sortOrder, $target, $cssClass]);

        return (int) $this->pdo->lastInsertId();
    }

    public function update(
        int $id,
        string $label,
        string $url,
        ?int $pageId,
        int $sortOrder,
        string $target,
        string $cssClass
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE ' . self::TABLE . '
             SET label = ?, url = ?, page_id = ?, sort_order = ?, target = ?, css_class = ? WHERE id = ?'
        );
        $stmt->execute([$label, $url, $pageId, $sortOrder, $target, $cssClass, $id]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM ' . self::TABLE . ' WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function updateSortOrder(int $menuId, int $itemId, int $sortOrder): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE ' . self::TABLE . ' SET sort_order = ? WHERE id = ? AND menu_id = ?'
        );
        $stmt->execute([$sortOrder, $itemId, $menuId]);
    }

    public function belongsToMenu(int $itemId, int $menuId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM ' . self::TABLE . ' WHERE id = ? AND menu_id = ? LIMIT 1');
        $stmt->execute([$itemId, $menuId]);

        return (bool) $stmt->fetchColumn();
    }

    public function nextSortOrder(int $menuId): int
    {
        $stmt = $this->pdo->prepare('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM ' . self::TABLE . ' WHERE menu_id = ?');
        $stmt->execute([$menuId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Remove items whose URL targets this content type’s public routes: index `/{slug}`,
     * entries `/{slug}/…`, taxonomy archives `/{slug}/…/…`, or index with query `/{slug}?…`.
     */
    public function deleteByContentTypeSlug(string $slug): int
    {
        $slug = trim($slug);
        if ($slug === '') {
            return 0;
        }
        $path = '/' . $slug;
        $stmt = $this->pdo->prepare(
            'DELETE FROM ' . self::TABLE . ' WHERE url = ? OR url = ? OR url LIKE ? OR url LIKE ?
             OR url = ? OR url = ? OR url LIKE ? OR url LIKE ?'
        );
        $stmt->execute([
            $path,
            $path . '/',
            $path . '/%',
            $path . '?%',
            $slug,
            $slug . '/',
            $slug . '/%',
            $slug . '?%',
        ]);

        return $stmt->rowCount();
    }
}
