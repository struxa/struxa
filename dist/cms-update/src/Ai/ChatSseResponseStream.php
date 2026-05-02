<?php

declare(strict_types=1);

namespace App\Ai;

use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * PSR-7 body that streams {@see OpenAiChatStreamProcessor} output (SSE text).
 */
final class ChatSseResponseStream implements StreamInterface
{
    private int $position = 0;

    private bool $closed = false;

    public function __construct(
        private OpenAiChatStreamProcessor $processor
    ) {
    }

    public function __toString(): string
    {
        try {
            return $this->getContents();
        } catch (\Throwable) {
            return '';
        }
    }

    public function close(): void
    {
        $this->closed = true;
        $this->processor->close();
    }

    public function detach()
    {
        $this->close();

        return null;
    }

    public function getSize(): ?int
    {
        return null;
    }

    public function tell(): int
    {
        return $this->position;
    }

    public function eof(): bool
    {
        return $this->closed || $this->processor->isFinished();
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek($offset, $whence = SEEK_SET): void
    {
        throw new RuntimeException('Stream is not seekable.');
    }

    public function rewind(): void
    {
        throw new RuntimeException('Stream is not seekable.');
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write($string): int
    {
        throw new RuntimeException('Stream is not writable.');
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function read($length): string
    {
        if ($this->closed) {
            return '';
        }
        $chunk = $this->processor->readUpTo(max(1, (int) $length));
        $this->position += strlen($chunk);

        return $chunk;
    }

    public function getContents(): string
    {
        $buf = '';
        while (!$this->eof()) {
            $buf .= $this->read(8192);
        }

        return $buf;
    }

    public function getMetadata($key = null)
    {
        if ($key === null) {
            return [];
        }

        return null;
    }
}
