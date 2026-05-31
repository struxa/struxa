<?php

declare(strict_types=1);

namespace App\Commerce\Order;

/** Exports commerce orders as CSV for admin download. */
final class CommerceOrderCsvExporter
{
    /**
     * @param list<CommerceOrder> $orders
     */
    public function export(array $orders): string
    {
        $fh = fopen('php://temp', 'r+');
        if ($fh === false) {
            throw new \RuntimeException('Could not open temp stream for CSV export.');
        }

        fputcsv($fh, [
            'order_number',
            'status',
            'currency',
            'subtotal',
            'discount',
            'tax',
            'shipping',
            'total',
            'coupon_code',
            'customer_email',
            'created_at',
            'paid_at',
        ]);

        foreach ($orders as $order) {
            fputcsv($fh, [
                $order->orderNumber,
                $order->status,
                $order->currency,
                $this->money($order->subtotalCents),
                $this->money($order->discountCents),
                $this->money($order->taxCents),
                $this->money($order->shippingCents),
                $this->money($order->totalCents),
                $order->couponCode ?? '',
                $order->customerEmail ?? '',
                $order->createdAt,
                $order->paidAt ?? '',
            ]);
        }

        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);

        return is_string($csv) ? $csv : '';
    }

    private function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
