<?php

declare(strict_types=1);

namespace App\Dev;

/**
 * Single finding from {@see TwigLayoutContractLinter}.
 */
final class TwigLayoutContractIssue
{
    public function __construct(
        public readonly string $severity, // 'error' | 'warning'
        public readonly string $code,
        public readonly string $message,
        public readonly string $file,
        public readonly ?string $extendsTarget = null,
    ) {
    }

    public function isError(): bool
    {
        return $this->severity === 'error';
    }

    public function formatLine(): string
    {
        $rel = $this->file;
        $ext = $this->extendsTarget !== null ? " (extends: {$this->extendsTarget})" : '';

        return sprintf(
            '[%s] %s%s%s',
            strtoupper($this->severity),
            $this->code,
            $ext,
            "\n  " . $rel . "\n  → " . $this->message
        );
    }
}
