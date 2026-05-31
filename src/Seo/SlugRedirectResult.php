<?php

declare(strict_types=1);

namespace App\Seo;

final class SlugRedirectResult
{
    public function __construct(
        public readonly bool $created,
        public readonly string $fromPath = '',
        public readonly string $toUrl = '',
        public readonly int $chainUpdated = 0,
    ) {
    }

    public static function none(): self
    {
        return new self(false);
    }

    public static function skipped(): self
    {
        return new self(false);
    }

    public function flashSuffix(): string
    {
        if (!$this->created) {
            return '';
        }
        $msg = ' Added 301 redirect from ' . $this->fromPath . ' → ' . $this->toUrl . '.';
        if ($this->chainUpdated > 0) {
            $msg .= ' Updated ' . $this->chainUpdated . ' older redirect(s) in the chain.';
        }

        return $msg;
    }
}
