<?php

declare(strict_types=1);

namespace App\Media;

/**
 * Whitelist parsing for /admin/media list query params.
 */
final class MediaLibraryListOptions
{
    public const SORT_NEWEST = 'newest';
    public const SORT_OLDEST = 'oldest';
    public const SORT_NAME = 'name';
    public const SORT_SIZE = 'size';

    /** @var list<int> */
    private const PER_PAGE_CHOICES = [24, 48, 96];

    public readonly string $sort;
    public readonly int $perPage;

    public function __construct(string $sortRaw, int $perPageRaw)
    {
        $this->sort = self::normalizeSort($sortRaw);
        $this->perPage = self::normalizePerPage($perPageRaw);
    }

    public static function fromQueryParams(array $query): self
    {
        $sort = isset($query['sort']) && is_string($query['sort']) ? $query['sort'] : self::SORT_NEWEST;
        $perPage = isset($query['per_page']) ? (int) $query['per_page'] : 24;

        return new self($sort, $perPage);
    }

    /** @return list<int> */
    public static function perPageChoices(): array
    {
        return self::PER_PAGE_CHOICES;
    }

    /** @return list<string> */
    public static function sortChoices(): array
    {
        return [self::SORT_NEWEST, self::SORT_OLDEST, self::SORT_NAME, self::SORT_SIZE];
    }

    public function orderBySql(): string
    {
        return match ($this->sort) {
            self::SORT_OLDEST => 'm.created_at ASC, m.id ASC',
            self::SORT_NAME => 'm.original_name ASC, m.id DESC',
            self::SORT_SIZE => 'm.file_size DESC, m.id DESC',
            default => 'm.created_at DESC, m.id DESC',
        };
    }

    public function sortLabel(): string
    {
        return match ($this->sort) {
            self::SORT_OLDEST => 'Oldest first',
            self::SORT_NAME => 'Name A–Z',
            self::SORT_SIZE => 'Largest first',
            default => 'Newest first',
        };
    }

    private static function normalizeSort(string $sort): string
    {
        $sort = strtolower(trim($sort));

        return in_array($sort, self::sortChoices(), true) ? $sort : self::SORT_NEWEST;
    }

    private static function normalizePerPage(int $perPage): int
    {
        return in_array($perPage, self::PER_PAGE_CHOICES, true) ? $perPage : 24;
    }
}
