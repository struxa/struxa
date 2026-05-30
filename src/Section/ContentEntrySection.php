<?php

declare(strict_types=1);

namespace App\Section;

final class ContentEntrySection
{
    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $options
     */
    public function __construct(
        public readonly int $id,
        public readonly int $contentEntryId,
        public readonly int $sortOrder,
        public readonly string $sectionKey,
        public readonly array $data,
        public readonly array $options,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $data = self::decodeJsonObject((string) ($row['data_json'] ?? '{}'));
        $opts = self::decodeJsonObject((string) ($row['options_json'] ?? '{}'));

        return new self(
            (int) $row['id'],
            (int) $row['content_entry_id'],
            (int) $row['sort_order'],
            (string) $row['section_key'],
            $data,
            $opts,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeJsonObject(string $json): array
    {
        if ($json === '') {
            return [];
        }
        try {
            $v = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($v) ? $v : [];
    }
}
