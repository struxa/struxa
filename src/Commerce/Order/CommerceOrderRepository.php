<?php

declare(strict_types=1);

namespace App\Commerce\Order;

use PDO;

final class CommerceOrderRepository
{
    private const ORDERS = 'cms_commerce_orders';
    private const ITEMS = 'cms_commerce_order_items';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function findById(int $id): ?CommerceOrder
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ' . self::ORDERS . ' WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : CommerceOrder::fromRow($row, $this->itemsForOrder($id));
    }

    public function findByOrderNumber(string $orderNumber): ?CommerceOrder
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ' . self::ORDERS . ' WHERE order_number = ? LIMIT 1');
        $stmt->execute([$orderNumber]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : CommerceOrder::fromRow($row, $this->itemsForOrder((int) $row['id']));
    }

    public function findByStripeSessionId(string $sessionId): ?CommerceOrder
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ' . self::ORDERS . ' WHERE stripe_checkout_session_id = ? LIMIT 1');
        $stmt->execute([$sessionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : CommerceOrder::fromRow($row, $this->itemsForOrder((int) $row['id']));
    }

    /**
     * @return list<CommerceOrder>
     */
    public function listFiltered(OrderListFilter $filter): array
    {
        $limit = max(1, min(5000, $filter->limit));
        $where = ['1=1'];
        $params = [];

        if ($filter->status !== null) {
            $where[] = 'status = ?';
            $params[] = $filter->status;
        }
        if ($filter->email !== null) {
            $where[] = 'LOWER(customer_email) LIKE ?';
            $params[] = '%' . strtolower($filter->email) . '%';
        }
        if ($filter->orderNumber !== null) {
            $where[] = 'order_number LIKE ?';
            $params[] = '%' . $filter->orderNumber . '%';
        }
        if ($filter->dateFrom !== null) {
            $where[] = 'DATE(created_at) >= ?';
            $params[] = $filter->dateFrom;
        }
        if ($filter->dateTo !== null) {
            $where[] = 'DATE(created_at) <= ?';
            $params[] = $filter->dateTo;
        }

        $sql = 'SELECT * FROM ' . self::ORDERS . ' WHERE ' . implode(' AND ', $where)
            . ' ORDER BY created_at DESC, id DESC LIMIT ' . $limit;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $id = (int) $row['id'];
            $out[] = CommerceOrder::fromRow($row, $this->itemsForOrder($id));
        }

        return $out;
    }

    /**
     * @return list<CommerceOrder>
     */
    public function listRecent(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $stmt = $this->pdo->query(
            'SELECT * FROM ' . self::ORDERS . ' ORDER BY created_at DESC, id DESC LIMIT ' . $limit
        );
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $id = (int) $row['id'];
            $out[] = CommerceOrder::fromRow($row, $this->itemsForOrder($id));
        }

        return $out;
    }

    /**
     * @param array{
     *   currency: string,
     *   subtotal_cents: int,
     *   discount_cents?: int,
     *   tax_cents?: int,
     *   shipping_cents?: int,
     *   total_cents: int,
     *   coupon_code?: ?string,
     *   shipping_label?: ?string,
     *   customer_email?: ?string,
     *   customer_user_id?: ?int,
     *   stripe_checkout_session_id?: ?string,
     *   metadata_json?: ?string,
     * } $data
     * @param list<array{
     *   content_entry_id: int,
     *   content_type_id: int,
     *   title: string,
     *   unit_price_cents: int,
     *   quantity: int,
     *   line_total_cents: int,
     *   metadata_json?: ?string,
     * }> $items
     */
    public function createPending(array $data, array $items): CommerceOrder
    {
        $orderNumber = $this->generateOrderNumber();
        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . self::ORDERS . '
             (order_number, status, currency, subtotal_cents, discount_cents, tax_cents, shipping_cents, total_cents,
              coupon_code, shipping_label, customer_email, customer_user_id, stripe_checkout_session_id, metadata_json)
             VALUES (?, \'pending\', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $orderNumber,
            strtolower($data['currency']),
            $data['subtotal_cents'],
            $data['discount_cents'] ?? 0,
            $data['tax_cents'] ?? 0,
            $data['shipping_cents'] ?? 0,
            $data['total_cents'],
            $data['coupon_code'] ?? null,
            $data['shipping_label'] ?? null,
            $data['customer_email'] ?? null,
            isset($data['customer_user_id']) && (int) $data['customer_user_id'] > 0 ? (int) $data['customer_user_id'] : null,
            $data['stripe_checkout_session_id'] ?? null,
            $data['metadata_json'] ?? null,
        ]);
        $orderId = (int) $this->pdo->lastInsertId();

        $itemStmt = $this->pdo->prepare(
            'INSERT INTO ' . self::ITEMS . '
             (order_id, content_entry_id, content_type_id, title, unit_price_cents, quantity, line_total_cents, metadata_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($items as $item) {
            $itemStmt->execute([
                $orderId,
                $item['content_entry_id'],
                $item['content_type_id'],
                $item['title'],
                $item['unit_price_cents'],
                $item['quantity'],
                $item['line_total_cents'],
                $item['metadata_json'] ?? null,
            ]);
        }

        $order = $this->findById($orderId);
        if ($order === null) {
            throw new \RuntimeException('Failed to load order after insert.');
        }

        return $order;
    }

    public function attachStripeSession(int $orderId, string $sessionId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE ' . self::ORDERS . ' SET stripe_checkout_session_id = ? WHERE id = ?'
        );
        $stmt->execute([$sessionId, $orderId]);
    }

    public function markPaid(int $orderId, ?string $paymentIntentId, ?string $customerEmail): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE ' . self::ORDERS . '
             SET status = \'paid\', stripe_payment_intent_id = COALESCE(?, stripe_payment_intent_id),
                 customer_email = COALESCE(?, customer_email), paid_at = COALESCE(paid_at, CURRENT_TIMESTAMP)
             WHERE id = ? AND status = \'pending\''
        );
        $stmt->execute([$paymentIntentId, $customerEmail, $orderId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * @param array<string, mixed> $address
     */
    public function saveShippingAddress(int $orderId, array $address): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE ' . self::ORDERS . ' SET shipping_address_json = ? WHERE id = ?'
        );
        $stmt->execute([json_encode($address, JSON_THROW_ON_ERROR), $orderId]);
    }

    public function markFailed(int $orderId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE ' . self::ORDERS . ' SET status = \'failed\' WHERE id = ? AND status = \'pending\''
        );
        $stmt->execute([$orderId]);
    }

    public function markCancelled(int $orderId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE ' . self::ORDERS . ' SET status = \'cancelled\' WHERE id = ? AND status = \'pending\''
        );
        $stmt->execute([$orderId]);
    }

    public function markRefunded(int $orderId, string $stripeRefundId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE ' . self::ORDERS . ' SET status = \'refunded\', stripe_refund_id = ? WHERE id = ? AND status = \'paid\''
        );
        $stmt->execute([$stripeRefundId, $orderId]);
    }

    public function markConfirmationEmailSent(int $orderId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE ' . self::ORDERS . ' SET confirmation_email_sent_at = COALESCE(confirmation_email_sent_at, CURRENT_TIMESTAMP) WHERE id = ?'
        );
        $stmt->execute([$orderId]);
    }

    /**
     * @param array<string, mixed> $meta
     */
    public function updateMetadata(int $orderId, array $meta): void
    {
        $stmt = $this->pdo->prepare('UPDATE ' . self::ORDERS . ' SET metadata_json = ? WHERE id = ?');
        $stmt->execute([json_encode($meta, JSON_THROW_ON_ERROR), $orderId]);
    }

    public function linkCustomerUser(int $orderId, int $userId): void
    {
        if ($userId < 1) {
            return;
        }
        $stmt = $this->pdo->prepare(
            'UPDATE ' . self::ORDERS . ' SET customer_user_id = ? WHERE id = ? AND (customer_user_id IS NULL OR customer_user_id = 0)'
        );
        $stmt->execute([$userId, $orderId]);
    }

    /**
     * @return list<CommerceOrder>
     */
    public function listForCustomer(int $userId, string $email, int $limit = 50): array
    {
        $email = strtolower(trim($email));
        $limit = max(1, min(200, $limit));
        if ($userId > 0) {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM ' . self::ORDERS . '
                 WHERE status IN (\'paid\', \'refunded\')
                   AND (customer_user_id = ? OR (customer_user_id IS NULL AND LOWER(customer_email) = ?))
                 ORDER BY created_at DESC, id DESC LIMIT ' . $limit
            );
            $stmt->execute([$userId, $email]);
        } elseif ($email !== '') {
            return $this->listByCustomerEmail($email, $limit);
        } else {
            return [];
        }
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $id = (int) $row['id'];
            $out[] = CommerceOrder::fromRow($row, $this->itemsForOrder($id));
        }

        return $out;
    }

    public function findByOrderNumberForCustomer(string $orderNumber, int $userId, string $email): ?CommerceOrder
    {
        $order = $this->findByOrderNumber($orderNumber);
        if ($order === null) {
            return null;
        }
        if (!in_array($order->status, ['paid', 'refunded', 'pending'], true)) {
            return null;
        }
        if ($userId > 0 && $order->customerUserId === $userId) {
            return $order;
        }
        $email = strtolower(trim($email));
        if ($email !== '' && strtolower(trim((string) ($order->customerEmail ?? ''))) === $email) {
            return $order;
        }

        return null;
    }

    /**
     * @return list<CommerceOrder>
     */
    public function listByCustomerEmail(string $email, int $limit = 50): array
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return [];
        }
        $limit = max(1, min(200, $limit));
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ' . self::ORDERS . '
             WHERE LOWER(customer_email) = ? AND status IN (\'paid\', \'refunded\')
             ORDER BY created_at DESC, id DESC LIMIT ' . $limit
        );
        $stmt->execute([$email]);
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $id = (int) $row['id'];
            $out[] = CommerceOrder::fromRow($row, $this->itemsForOrder($id));
        }

        return $out;
    }

    public function findByOrderNumberAndEmail(string $orderNumber, string $email): ?CommerceOrder
    {
        $orderNumber = strtoupper(trim($orderNumber));
        $email = strtolower(trim($email));
        if ($orderNumber === '' || $email === '') {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ' . self::ORDERS . '
             WHERE order_number = ? AND LOWER(customer_email) = ? AND status IN (\'paid\', \'refunded\', \'pending\')
             LIMIT 1'
        );
        $stmt->execute([$orderNumber, $email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : CommerceOrder::fromRow($row, $this->itemsForOrder((int) $row['id']));
    }

    /**
     * @return list<CommerceOrderItem>
     */
    private function itemsForOrder(int $orderId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ' . self::ITEMS . ' WHERE order_id = ? ORDER BY id ASC'
        );
        $stmt->execute([$orderId]);
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[] = CommerceOrderItem::fromRow($row);
        }

        return $out;
    }

    private function generateOrderNumber(): string
    {
        for ($i = 0; $i < 8; $i++) {
            $candidate = 'STX-' . strtoupper(bin2hex(random_bytes(4)));
            $stmt = $this->pdo->prepare('SELECT 1 FROM ' . self::ORDERS . ' WHERE order_number = ? LIMIT 1');
            $stmt->execute([$candidate]);
            if ($stmt->fetchColumn() === false) {
                return $candidate;
            }
        }

        return 'STX-' . strtoupper(substr(sha1((string) microtime(true)), 0, 8));
    }
}
