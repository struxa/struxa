<?php

declare(strict_types=1);

namespace App\Commerce\Inventory;

use App\Commerce\CommerceSettings;
use App\Commerce\Product\ProductResolver;
use App\Content\ContentEntryRepository;
use App\Content\ContentEntryValueRepository;
use App\Content\ContentTypeRepository;
use PDO;

final class LowStockReportService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly CommerceSettings $commerce,
        private readonly ContentTypeRepository $types,
        private readonly ContentEntryRepository $entries,
        private readonly ContentEntryValueRepository $values,
        private readonly ProductResolver $products,
    ) {
    }

    /**
     * @return list<LowStockItem>
     */
    public function items(?int $threshold = null): array
    {
        if (!$this->commerce->trackInventory()) {
            return [];
        }

        $threshold ??= $this->commerce->lowStockThreshold();
        $threshold = max(0, $threshold);

        $type = $this->types->findBySlug($this->commerce->productTypeSlug());
        if ($type === null) {
            return [];
        }

        $fieldMap = $this->products->fieldKeyMapForType($type->id);
        $stockFieldId = $fieldMap[CommerceSettings::FIELD_STOCK_QTY] ?? null;
        if ($stockFieldId === null) {
            return [];
        }

        $stmt = $this->pdo->prepare(
            'SELECT e.id, e.title, e.slug, v.value_text AS stock_raw
             FROM cms_content_entries e
             INNER JOIN cms_content_entry_values v ON v.content_entry_id = e.id AND v.content_field_id = ?
             WHERE e.content_type_id = ? AND e.deleted_at IS NULL AND e.status = \'published\'
               AND v.value_text REGEXP \'^[0-9]+$\'
               AND CAST(v.value_text AS UNSIGNED) <= ?
             ORDER BY CAST(v.value_text AS UNSIGNED) ASC, e.title ASC
             LIMIT 500'
        );
        $stmt->execute([$stockFieldId, $type->id, $threshold]);

        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $qty = max(0, (int) ($row['stock_raw'] ?? 0));
            $out[] = new LowStockItem(
                (int) $row['id'],
                (string) $row['title'],
                (string) $row['slug'],
                $qty,
                $type->id,
            );
        }

        return $out;
    }
}
