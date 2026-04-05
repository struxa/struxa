<?php

declare(strict_types=1);

namespace App\Menu;

final class MenuItem
{
    public function __construct(
        public readonly int $id,
        public readonly int $menuId,
        public readonly string $label,
        public readonly string $url,
        public readonly ?int $pageId,
        public readonly int $sortOrder,
        public readonly string $target,
        public readonly string $cssClass,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $pageId = $row['page_id'];

        return new self(
            (int) $row['id'],
            (int) $row['menu_id'],
            (string) $row['label'],
            (string) ($row['url'] ?? ''),
            $pageId === null || $pageId === '' ? null : (int) $pageId,
            (int) ($row['sort_order'] ?? 0),
            (string) ($row['target'] ?? '_self'),
            (string) ($row['css_class'] ?? ''),
            (string) ($row['created_at'] ?? ''),
            (string) ($row['updated_at'] ?? ''),
        );
    }
}
