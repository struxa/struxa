<?php

declare(strict_types=1);

namespace App\Plugin;

/**
 * Structured compatibility result for plugin activation and admin UI.
 */
final class PluginCompatibilityReport
{
    /**
     * @param list<string> $errors
     * @param list<string> $warnings
     * @param list<array{label: string, status: string, detail: string}> $checks
     */
    public function __construct(
        public readonly array $errors = [],
        public readonly array $warnings = [],
        public readonly array $checks = [],
    ) {
    }

    public function canActivate(): bool
    {
        return $this->errors === [];
    }

    /**
     * @return list<string>
     */
    public function activationErrors(): array
    {
        return $this->errors;
    }

    public function statusLabel(): string
    {
        if ($this->errors !== []) {
            return 'blocked';
        }
        if ($this->warnings !== []) {
            return 'warnings';
        }

        return 'ready';
    }
}
