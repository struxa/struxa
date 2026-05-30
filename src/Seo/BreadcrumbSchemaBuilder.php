<?php

declare(strict_types=1);

namespace App\Seo;

/**
 * Builds schema.org BreadcrumbList JSON-LD for public pages.
 *
 * @param list<array{name: string, url?: string}> $crumbs Last item may omit url (current page).
 */
final class BreadcrumbSchemaBuilder
{
    /**
     * @param list<array{name: string, url?: string}> $crumbs
     */
    public static function build(array $crumbs, string $siteUrl): ?string
    {
        if ($crumbs === []) {
            return null;
        }
        $siteUrl = rtrim($siteUrl, '/');
        $items = [];
        $pos = 1;
        foreach ($crumbs as $crumb) {
            $name = trim($crumb['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $item = [
                '@type' => 'ListItem',
                'position' => $pos,
                'name' => $name,
            ];
            $url = trim($crumb['url'] ?? '');
            if ($url !== '') {
                $item['item'] = MetaTagBuilder::absoluteUrl($siteUrl, $url);
            }
            $items[] = $item;
            ++$pos;
        }
        if ($items === []) {
            return null;
        }

        $graph = [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => $items,
        ];
        $encoded = json_encode($graph, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return $encoded;
    }
}
