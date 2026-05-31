<?php

declare(strict_types=1);

namespace App\Commerce\Digital;

final class DigitalDeliverySpec
{
    public const TYPE_FILE = 'file';
    public const TYPE_URL = 'url';
    public const TYPE_ENTRY = 'entry';

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly string $type,
        public readonly array $payload,
        public readonly string $label = 'Download',
    ) {
    }

    public function hasDelivery(): bool
    {
        return match ($this->type) {
            self::TYPE_FILE => isset($this->payload['media_id']) && (int) $this->payload['media_id'] > 0,
            self::TYPE_URL => isset($this->payload['url']) && trim((string) $this->payload['url']) !== '',
            self::TYPE_ENTRY => isset($this->payload['type_slug'], $this->payload['entry_slug'])
                && trim((string) $this->payload['entry_slug']) !== '',
            default => false,
        };
    }
}
