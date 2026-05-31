<?php

declare(strict_types=1);

namespace App\Commerce\Product;

use App\Commerce\CommerceSettings;
use App\Content\ContentEntry;
use App\Content\ContentFieldRepository;
use App\Content\ContentType;
use PDO;

/**
 * Resolves purchasable products from content-type entries using convention field keys.
 */
final class ProductResolver
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly CommerceSettings $commerce,
        private readonly ContentFieldRepository $fields,
    ) {
    }

    public function isProductType(ContentType $type): bool
    {
        if (!$this->commerce->isEnabled()) {
            return false;
        }

        return $type->slug === $this->commerce->productTypeSlug();
    }

    /**
     * @param array<int, string> $valueMap field_id => stored value
     */
    public function resolvePublished(ContentType $type, ContentEntry $entry, array $valueMap, bool $requireInStock = true): ?PurchasableProduct
    {
        if (!$this->isProductType($type) || $entry->status !== 'published') {
            return null;
        }

        $fieldMap = $this->fieldKeyMap($type->id);
        $priceRaw = $this->valueForKey($valueMap, $fieldMap, CommerceSettings::FIELD_PRICE_CENTS);
        $stripePriceId = $this->optionalString($this->valueForKey($valueMap, $fieldMap, CommerceSettings::FIELD_STRIPE_PRICE_ID));
        $purchasableRaw = $this->valueForKey($valueMap, $fieldMap, CommerceSettings::FIELD_PURCHASABLE);
        $sku = $this->optionalString($this->valueForKey($valueMap, $fieldMap, CommerceSettings::FIELD_SKU));
        $stockRaw = $this->valueForKey($valueMap, $fieldMap, CommerceSettings::FIELD_STOCK_QTY);
        $stockQty = $this->parseStockQty($stockRaw);

        if ($purchasableRaw !== null && in_array(strtolower(trim($purchasableRaw)), ['0', 'false', 'no', 'off'], true)) {
            return null;
        }

        $priceCents = $this->parsePriceCents($priceRaw);
        if ($stripePriceId === null && ($priceCents === null || $priceCents < 1)) {
            return null;
        }

        $product = new PurchasableProduct(
            $entry->id,
            $type->id,
            $type->slug,
            $entry->slug,
            $entry->title,
            $priceCents ?? 0,
            $this->commerce->defaultCurrency(),
            $stripePriceId,
            $sku,
            $stockQty,
        );

        if ($requireInStock && !$this->hasStock($product, 1)) {
            return null;
        }

        return $product;
    }

    public function hasStock(PurchasableProduct $product, int $quantity): bool
    {
        if (!$this->commerce->trackInventory()) {
            return true;
        }

        return $product->isInStock($quantity);
    }

    /**
     * @return array<string, int> field_key => field_id
     */
    public function fieldKeyMapForType(int $contentTypeId): array
    {
        return $this->fieldKeyMap($contentTypeId);
    }

    /**
     * @return array<string, int> field_key => field_id
     */
    private function fieldKeyMap(int $contentTypeId): array
    {
        $map = [];
        foreach ($this->fields->forTypeOrdered($contentTypeId) as $field) {
            $map[$field->fieldKey] = $field->id;
        }

        return $map;
    }

    /**
     * @param array<int, string> $valueMap
     * @param array<string, int> $fieldMap
     */
    private function valueForKey(array $valueMap, array $fieldMap, string $key): ?string
    {
        if (!isset($fieldMap[$key])) {
            return null;
        }
        $fid = $fieldMap[$key];

        return array_key_exists($fid, $valueMap) ? (string) $valueMap[$fid] : null;
    }

    private function parsePriceCents(?string $raw): ?int
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }
        if (ctype_digit(trim($raw))) {
            return max(0, (int) trim($raw));
        }
        if (preg_match('/^\d+(\.\d{1,2})?$/', trim($raw)) === 1) {
            return (int) round((float) trim($raw) * 100);
        }

        return null;
    }

    private function optionalString(?string $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $v = trim($v);

        return $v !== '' ? $v : null;
    }

    private function parseStockQty(?string $raw): ?int
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }
        if (!ctype_digit(trim($raw))) {
            return null;
        }

        return max(0, (int) trim($raw));
    }
}
