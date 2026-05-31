<?php

declare(strict_types=1);

namespace App\Commerce\Digital;

use App\Commerce\Order\CommerceOrder;
use App\Commerce\Order\CommerceOrderRepository;
use App\Content\ContentEntryValueRepository;
use App\Content\ContentFieldRepository;
use App\Content\ContentTypeRepository;
use App\Media\MediaRepository;

/**
 * Creates and revokes digital access grants when orders are paid or refunded.
 */
final class DigitalFulfillmentService
{
    public function __construct(
        private readonly DigitalGrantRepository $grants,
        private readonly DigitalDeliveryResolver $delivery,
        private readonly CommerceOrderRepository $orders,
    ) {
    }

    public function issueForOrder(int $orderId): void
    {
        $order = $this->orders->findById($orderId);
        if ($order === null || $order->status !== 'paid') {
            return;
        }

        foreach ($order->items as $item) {
            if ($this->grants->existsForOrderItem($item->id)) {
                continue;
            }
            $spec = $this->delivery->forEntryId($item->contentEntryId);
            if ($spec === null || !$spec->hasDelivery()) {
                continue;
            }
            $this->grants->create($order->id, $item->id, $item->contentEntryId, $spec);
        }
    }

    public function revokeForOrder(int $orderId): void
    {
        $this->grants->revokeForOrder($orderId);
    }

    /**
     * @return list<DigitalGrant>
     */
    public function activeGrantsForOrder(CommerceOrder $order): array
    {
        return $this->grants->forOrder($order->id, true);
    }

    /**
     * @return list<array{label: string, url: string}>
     */
    public function accessLinksForOrder(CommerceOrder $order, string $siteUrl): array
    {
        $base = rtrim($siteUrl, '/');
        $links = [];
        foreach ($this->activeGrantsForOrder($order) as $grant) {
            $links[] = [
                'label' => $grant->label,
                'url' => $base . '/commerce/access/' . $grant->accessToken,
            ];
        }

        return $links;
    }

    public static function factory(
        \PDO $pdo,
        \App\Commerce\CommerceSettings $commerce,
        ContentTypeRepository $types,
        ContentFieldRepository $fields,
        ContentEntryValueRepository $values,
        \App\Content\ContentEntryRepository $entries,
        CommerceOrderRepository $orders,
    ): self {
        return new self(
            new DigitalGrantRepository($pdo),
            new DigitalDeliveryResolver($commerce, $types, $fields, $values, $entries, new MediaRepository($pdo)),
            $orders,
        );
    }
}
