<?php

declare(strict_types=1);

namespace App\Commerce\Customer;

use App\Commerce\Order\CommerceOrderRepository;
use PDO;

/** Links paid orders to phpauth_users by ID or checkout email. */
final class CommerceCustomerLinker
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly CommerceOrderRepository $orders,
    ) {
    }

    public function userIdForEmail(string $email): ?int
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT id FROM phpauth_users WHERE LOWER(TRIM(email)) = ? LIMIT 1');
        $stmt->execute([$email]);
        $id = $stmt->fetchColumn();

        return $id !== false ? (int) $id : null;
    }

    public function linkOrderAfterPayment(int $orderId, ?string $customerEmail, ?int $checkoutUserId = null): void
    {
        if ($checkoutUserId !== null && $checkoutUserId > 0) {
            $this->orders->linkCustomerUser($orderId, $checkoutUserId);

            return;
        }
        if ($customerEmail === null || trim($customerEmail) === '') {
            return;
        }
        $userId = $this->userIdForEmail($customerEmail);
        if ($userId !== null) {
            $this->orders->linkCustomerUser($orderId, $userId);
        }
    }
}
