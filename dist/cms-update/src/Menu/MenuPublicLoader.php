<?php

declare(strict_types=1);

namespace App\Menu;

use App\Page\PageRepository;
use PDO;
use PDOException;

/**
 * Resolves menu rows for public Twig (href, label, target, css_class).
 */
final class MenuPublicLoader
{
    /** @var array<string, list<array{label: string, href: string, target: string, css_class: string}>> */
    private array $cache = [];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return list<array{label: string, href: string, target: string, css_class: string}>
     */
    public function forLocation(string $location): array
    {
        if (array_key_exists($location, $this->cache)) {
            return $this->cache[$location];
        }

        try {
            $menuRepo = new MenuRepository($this->pdo);
            $itemRepo = new MenuItemRepository($this->pdo);
            $pageRepo = new PageRepository($this->pdo);

            $menu = $menuRepo->findByLocation($location);
            if ($menu === null) {
                $this->cache[$location] = [];

                return $this->cache[$location];
            }

            $items = $itemRepo->forMenuOrdered($menu->id);
            $out = [];
            $seenHref = [];
            foreach ($items as $item) {
                $href = $this->resolveHref($item, $pageRepo);
                $key = $this->hrefDedupeKey($href);
                if (isset($seenHref[$key])) {
                    continue;
                }
                $seenHref[$key] = true;
                $out[] = [
                    'label' => $item->label,
                    'href' => $href,
                    'target' => $item->target,
                    'css_class' => $item->cssClass,
                ];
            }

            $this->cache[$location] = $out;

            return $this->cache[$location];
        } catch (PDOException) {
            $this->cache[$location] = [];

            return $this->cache[$location];
        }
    }

    private function resolveHref(MenuItem $item, PageRepository $pages): string
    {
        if ($item->pageId !== null) {
            $slug = $pages->findPublishedSlugById($item->pageId);
            if ($slug !== null && $slug !== '') {
                return '/p/' . rawurlencode($slug);
            }
        }

        $url = $item->url;

        return $url !== '' ? $url : '#';
    }

    private function hrefDedupeKey(string $href): string
    {
        $href = trim($href);
        if ($href === '' || $href === '#') {
            return $href;
        }
        if (preg_match('#^https?://#i', $href) === 1) {
            return strtolower($href);
        }

        return strtolower(rtrim($href, '/')) ?: '/';
    }
}
