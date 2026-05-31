<?php

declare(strict_types=1);

namespace App\Commerce\Mail;

use App\Commerce\Order\CommerceOrder;
use App\Commerce\Order\ShippingAddressFormatter;
use App\Settings;

final class CommerceMailer
{
    public function __construct(
        private readonly string $siteName,
        private readonly string $fromEmail,
    ) {
    }

    public static function fromSettings(): self
    {
        $site = trim(Settings::get('site_name') ?: 'Store');
        $from = trim((string) ($_ENV['PHPAUTH_SITE_EMAIL'] ?? 'no-reply@localhost'));

        return new self($site !== '' ? $site : 'Store', $from);
    }

    /**
     * @param list<array{label: string, url: string}> $accessLinks
     */
    public function sendOrderConfirmation(CommerceOrder $order, string $toEmail, array $accessLinks = []): bool
    {
        $subject = sprintf('Order %s confirmed — %s', $order->orderNumber, $this->siteName);
        $body = $this->buildOrderBody('Thank you for your order.', $order, $accessLinks);

        return $this->send($toEmail, $subject, $body);
    }

    /**
     * @param list<array{label: string, url: string}> $accessLinks
     */
    public function sendAdminNotification(CommerceOrder $order, string $toEmail, array $accessLinks = []): bool
    {
        $subject = sprintf('New order %s — %s', $order->orderNumber, $this->siteName);
        $body = $this->buildOrderBody('A new order was paid.', $order, $accessLinks);

        return $this->send($toEmail, $subject, $body);
    }

    /**
     * @param list<array{label: string, url: string}> $accessLinks
     */
    private function buildOrderBody(string $intro, CommerceOrder $order, array $accessLinks): string
    {
        $cur = strtoupper($order->currency);
        $lines = [$intro, '', 'Order: ' . $order->orderNumber, 'Status: ' . $order->status, ''];

        foreach ($order->items as $item) {
            $lines[] = sprintf(
                '- %s × %d — %.2f %s',
                $item->title,
                $item->quantity,
                $item->lineTotalCents / 100,
                $cur
            );
        }

        $lines[] = '';
        $lines[] = sprintf('Subtotal: %.2f %s', $order->subtotalCents / 100, $cur);
        if ($order->discountCents > 0) {
            $label = $order->couponCode !== null ? 'Discount (' . $order->couponCode . ')' : 'Discount';
            $lines[] = sprintf('%s: -%.2f %s', $label, $order->discountCents / 100, $cur);
        }
        if ($order->taxCents > 0) {
            $lines[] = sprintf('Tax: %.2f %s', $order->taxCents / 100, $cur);
        }
        if ($order->shippingCents > 0) {
            $shipLabel = $order->shippingLabel ?? 'Shipping';
            $lines[] = sprintf('%s: %.2f %s', $shipLabel, $order->shippingCents / 100, $cur);
        }
        $lines[] = sprintf('Total: %.2f %s', $order->totalCents / 100, $cur);

        if ($accessLinks !== []) {
            $lines[] = '';
            $lines[] = 'Your downloads:';
            foreach ($accessLinks as $link) {
                $lines[] = sprintf('- %s: %s', $link['label'], $link['url']);
            }
        }

        $shipLines = ShippingAddressFormatter::lines($order->shippingAddress);
        if ($shipLines !== []) {
            $lines[] = '';
            $lines[] = 'Ship to:';
            foreach ($shipLines as $line) {
                $lines[] = $line;
            }
        }

        return implode("\n", $lines);
    }

    private function send(string $to, string $subject, string $body): bool
    {
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        $headers = [
            'From: ' . $this->formatAddress($this->fromEmail),
            'Reply-To: ' . $this->fromEmail,
            'Content-Type: text/plain; charset=UTF-8',
            'X-Mailer: Struxa-Commerce',
        ];

        return @mail($to, $subject, $body, implode("\r\n", $headers));
    }

    private function formatAddress(string $email): string
    {
        $name = str_replace('"', '', $this->siteName);

        return sprintf('"%s" <%s>', $name, $email);
    }
}
