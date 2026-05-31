<?php

declare(strict_types=1);

namespace App\Commerce\Inventory;

use App\Commerce\CommerceSettings;
use App\Commerce\Order\CommerceOrder;
use App\Commerce\Product\ProductResolver;
use App\Content\ContentEntryValueRepository;
use App\Content\ContentTypeRepository;
use PDO;

final class InventoryService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly CommerceSettings $commerce,
        private readonly ContentTypeRepository $types,
        private readonly ContentEntryValueRepository $values,
        private readonly ProductResolver $products,
    ) {
    }

    public function decrementForOrder(CommerceOrder $order): void
    {
        if (!$this->commerce->trackInventory()) {
            return;
        }
        $type = $this->types->findBySlug($this->commerce->productTypeSlug());
        if ($type === null) {
            return;
        }
        $fieldMap = $this->products->fieldKeyMapForType($type->id);
        $stockFieldId = $fieldMap[CommerceSettings::FIELD_STOCK_QTY] ?? null;
        if ($stockFieldId === null) {
            return;
        }

        foreach ($order->items as $item) {
            $valueMap = $this->values->valuesByFieldIdForEntry($item->contentEntryId);
            $raw = $valueMap[$stockFieldId] ?? '';
            if ($raw === '' || !ctype_digit(trim($raw))) {
                continue;
            }
            $current = max(0, (int) trim($raw));
            $next = max(0, $current - $item->quantity);
            $this->values->upsert($item->contentEntryId, $stockFieldId, (string) $next);
        }
    }

    public function restoreForOrder(CommerceOrder $order): void
    {
        if (!$this->commerce->trackInventory()) {
            return;
        }
        $type = $this->types->findBySlug($this->commerce->productTypeSlug());
        if ($type === null) {
            return;
        }
        $fieldMap = $this->products->fieldKeyMapForType($type->id);
        $stockFieldId = $fieldMap[CommerceSettings::FIELD_STOCK_QTY] ?? null;
        if ($stockFieldId === null) {
            return;
        }

        foreach ($order->items as $item) {
            $valueMap = $this->values->valuesByFieldIdForEntry($item->contentEntryId);
            $raw = $valueMap[$stockFieldId] ?? '';
            if ($raw === '' || !ctype_digit(trim($raw))) {
                continue;
            }
            $current = max(0, (int) trim($raw));
            $next = $current + $item->quantity;
            $this->values->upsert($item->contentEntryId, $stockFieldId, (string) $next);
        }
    }
}
