<?php

declare(strict_types=1);

namespace App\Section;

final class SectionPattern
{
    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $options
     */
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $slug,
        public readonly ?string $description,
        public readonly string $host,
        public readonly string $sectionKey,
        public readonly array $data,
        public readonly array $options,
        public readonly ?int $createdBy,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) ($row['id'] ?? 0),
            (string) ($row['name'] ?? ''),
            (string) ($row['slug'] ?? ''),
            isset($row['description']) && $row['description'] !== null && $row['description'] !== ''
                ? (string) $row['description']
                : null,
            (string) ($row['host'] ?? SectionPatternHost::BOTH),
            (string) ($row['section_key'] ?? ''),
            self::decodeJsonObject((string) ($row['data_json'] ?? '{}')),
            self::decodeJsonObject((string) ($row['options_json'] ?? '{}')),
            isset($row['created_by']) && $row['created_by'] !== null ? (int) $row['created_by'] : null,
            (string) ($row['created_at'] ?? ''),
            (string) ($row['updated_at'] ?? ''),
        );
    }

    public function supportsHost(string $builderHost): bool
    {
        return SectionPatternHost::supports($this->host, $builderHost);
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
