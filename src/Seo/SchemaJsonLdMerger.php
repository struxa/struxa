<?php

declare(strict_types=1);

namespace App\Seo;

/**
 * Merges two JSON-LD documents into a single @graph block.
 */
final class SchemaJsonLdMerger
{
    public static function merge(?string $primary, ?string $secondary): ?string
    {
        $nodes = [];
        foreach ([$primary, $secondary] as $json) {
            if ($json === null || trim($json) === '') {
                continue;
            }
            $decoded = json_decode(trim($json), true, 512);
            if (!is_array($decoded)) {
                continue;
            }
            if (isset($decoded['@graph']) && is_array($decoded['@graph'])) {
                foreach ($decoded['@graph'] as $node) {
                    if (is_array($node)) {
                        $nodes[] = $node;
                    }
                }
            } else {
                $nodes[] = $decoded;
            }
        }
        if ($nodes === []) {
            return null;
        }
        if (count($nodes) === 1) {
            return json_encode($nodes[0], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }

        return json_encode(
            ['@context' => 'https://schema.org', '@graph' => $nodes],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }
}
