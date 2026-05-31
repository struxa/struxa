<?php

declare(strict_types=1);

namespace App\Commerce\Order;

use App\Commerce\CommerceSettings;
use App\Commerce\Coupon\CouponService;
use App\Commerce\Digital\DigitalFulfillmentService;
use App\Commerce\Inventory\InventoryService;
use App\Commerce\Mail\CommerceMailer;
use App\Settings\SiteUrlResolver;
use PDO;

/**
 * Post-payment side effects: inventory, digital grants, confirmation emails.
 */
final class OrderFulfillmentService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly CommerceSettings $commerce,
        private readonly CommerceOrderRepository $orders,
        private readonly InventoryService $inventory,
        private readonly CouponService $coupons,
        private readonly DigitalFulfillmentService $digital,
    ) {
    }

    public function fulfillIfPaid(int $orderId): void
    {
        $order = $this->orders->findById($orderId);
        if ($order === null || $order->status !== 'paid') {
            return;
        }

        $meta = $this->decodeMeta($order);
        if (empty($meta['inventory_adjusted'])) {
            $this->inventory->decrementForOrder($order);
            $meta['inventory_adjusted'] = true;
            $this->orders->updateMetadata($orderId, $meta);
        }

        if (empty($meta['digital_grants_issued'])) {
            $this->digital->issueForOrder($orderId);
            $meta['digital_grants_issued'] = true;
            $this->orders->updateMetadata($orderId, $meta);
        }

        if ($this->commerce->sendOrderEmails()) {
            if ($order->confirmationEmailSentAt === null) {
                $order = $this->orders->findById($orderId) ?? $order;
                $mailer = CommerceMailer::fromSettings();
                $accessLinks = $this->digital->accessLinksForOrder($order, SiteUrlResolver::resolve());
                $sent = false;
                if ($order->customerEmail !== null && $order->customerEmail !== '') {
                    $sent = $mailer->sendOrderConfirmation($order, $order->customerEmail, $accessLinks);
                }
                $notify = $this->commerce->notifyEmail();
                if ($notify !== '') {
                    $mailer->sendAdminNotification($order, $notify, $accessLinks);
                }
                if ($sent || $notify !== '') {
                    $this->orders->markConfirmationEmailSent($orderId);
                }
            }
        }

        $meta = $this->decodeMeta($order);
        if (empty($meta['coupon_redeemed']) && $order->couponCode !== null && $order->couponCode !== '') {
            $this->coupons->recordRedemption($order->couponCode);
            $meta['coupon_redeemed'] = true;
            $this->orders->updateMetadata($orderId, $meta);
        }
    }

    public function restoreInventoryIfRefunded(int $orderId): void
    {
        $order = $this->orders->findById($orderId);
        if ($order === null || $order->status !== 'refunded') {
            return;
        }
        $meta = $this->decodeMeta($order);
        if (!empty($meta['inventory_adjusted']) && empty($meta['inventory_restored'])) {
            $this->inventory->restoreForOrder($order);
            $meta['inventory_restored'] = true;
            $this->orders->updateMetadata($orderId, $meta);
        }
        if (empty($meta['digital_revoked'])) {
            $this->digital->revokeForOrder($orderId);
            $meta['digital_revoked'] = true;
            $this->orders->updateMetadata($orderId, $meta);
        }
    }

    public function resendDeliveryEmail(int $orderId): bool
    {
        $order = $this->orders->findById($orderId);
        if ($order === null || $order->status !== 'paid' || $order->customerEmail === null || $order->customerEmail === '') {
            return false;
        }
        $this->digital->issueForOrder($orderId);
        $order = $this->orders->findById($orderId) ?? $order;
        $links = $this->digital->accessLinksForOrder($order, SiteUrlResolver::resolve());

        return CommerceMailer::fromSettings()->sendOrderConfirmation($order, $order->customerEmail, $links);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeMeta(CommerceOrder $order): array
    {
        $stmt = $this->pdo->prepare('SELECT metadata_json FROM cms_commerce_orders WHERE id = ? LIMIT 1');
        $stmt->execute([$order->id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false || !isset($row['metadata_json']) || $row['metadata_json'] === null || $row['metadata_json'] === '') {
            return [];
        }
        try {
            $decoded = json_decode((string) $row['metadata_json'], true, 512, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : [];
        } catch (\JsonException) {
            return [];
        }
    }
}
