<?php

declare(strict_types=1);

namespace App\Commerce\Product;

use App\Content\ContentEntryRepository;
use App\Content\ContentEntryValueRepository;
use App\Content\ContentType;

/** Adds live commerce prices and stock flags to public catalog index cards. */
final class ProductCatalogEnricher
{
    public function __construct(
        private readonly ProductResolver $products,
        private readonly ContentEntryRepository $entries,
        private readonly ContentEntryValueRepository $values,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $indexRows
     * @return list<array<string, mixed>>
     */
    public function enrich(ContentType $type, array $indexRows): array
    {
        if (!$this->products->isProductType($type) || $indexRows === []) {
            return $indexRows;
        }

        $out = [];
        foreach ($indexRows as $item) {
            $row = $item['row'] ?? null;
            if (!is_array($row)) {
                $out[] = $item;

                continue;
            }
            $entryId = (int) ($row['id'] ?? 0);
            if ($entryId < 1) {
                $out[] = $item;

                continue;
            }
            $entry = $this->entries->findById($entryId);
            if ($entry === null) {
                $out[] = $item;

                continue;
            }
            $valueMap = $this->values->valuesByFieldIdForEntry($entryId);
            $product = $this->products->resolvePublished($type, $entry, $valueMap, requireInStock: false);
            if ($product !== null) {
                $item['commerce_product'] = $product;
                $item['price_plain'] = $product->formattedPrice();
                $item['commerce_in_stock'] = $this->products->hasStock($product, 1);
            }
            $out[] = $item;
        }

        return $out;
    }
}
