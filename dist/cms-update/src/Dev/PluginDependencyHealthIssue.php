<?php

declare(strict_types=1);

namespace App\Dev;

final class PluginDependencyHealthIssue
{
    public function __construct(
        public readonly string $severity,
        public readonly string $code,
        public readonly string $message,
        public readonly string $pluginSlug,
    ) {
    }

    public function isError(): bool
    {
        return $this->severity === 'error';
    }

    public function formatLine(): string
    {
        return sprintf(
            '[%s] %s (%s)%s%s',
            strtoupper($this->severity),
            $this->code,
            $this->pluginSlug,
            "\n  → ",
            $this->message
        );
    }
}
