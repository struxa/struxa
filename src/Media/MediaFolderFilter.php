<?php

declare(strict_types=1);

namespace App\Media;

/**
 * Parses ?folder= for /admin/media (all, unfiled, or a specific folder id).
 */
final class MediaFolderFilter
{
    public const MODE_ALL = 'all';
    public const MODE_UNFILED = 'unfiled';
    public const MODE_FOLDER = 'folder';

    public readonly string $mode;
    public readonly ?int $folderId;

    public function __construct(string $mode, ?int $folderId = null)
    {
        $this->mode = $mode;
        $this->folderId = $folderId;
    }

    public static function fromQueryParams(array $query): self
    {
        if (!isset($query['folder'])) {
            return new self(self::MODE_ALL);
        }

        $raw = $query['folder'];
        if (!is_string($raw) && !is_int($raw)) {
            return new self(self::MODE_ALL);
        }

        $raw = is_int($raw) ? (string) $raw : trim($raw);
        if ($raw === '' || strtolower($raw) === 'all') {
            return new self(self::MODE_ALL);
        }
        if ($raw === '0' || strtolower($raw) === 'unfiled') {
            return new self(self::MODE_UNFILED);
        }
        if (ctype_digit($raw)) {
            $id = (int) $raw;
            if ($id > 0) {
                return new self(self::MODE_FOLDER, $id);
            }
        }

        return new self(self::MODE_ALL);
    }

    /**
     * @return array<string, string|int>
     */
    public function toQueryParams(): array
    {
        return match ($this->mode) {
            self::MODE_UNFILED => ['folder' => 'unfiled'],
            self::MODE_FOLDER => $this->folderId !== null && $this->folderId > 0
                ? ['folder' => $this->folderId]
                : [],
            default => [],
        };
    }

    public function label(): string
    {
        return match ($this->mode) {
            self::MODE_UNFILED => 'Unfiled',
            self::MODE_FOLDER => 'Folder',
            default => 'All files',
        };
    }
}
