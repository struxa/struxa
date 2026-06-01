<?php

declare(strict_types=1);

namespace App\Content\List;

/**
 * Parsed list query definition (stored as JSON on cms_content_lists).
 */
final class ContentListDefinition
{
    public const MAX_FIELD_FILTERS = 3;

    public const PER_PAGE_DEFAULT = 12;

    public const PER_PAGE_MAX = 50;

    /** @param list<string> $statuses */
    public function __construct(
        public readonly array $statuses,
        public readonly ?int $taxonomyTermId,
        /** @var list<array{field_key: string, op: string, value: string}> */
        public readonly array $fieldFilters,
        public readonly string $sortField,
        public readonly string $sortDirection,
        public readonly int $perPage,
        public readonly bool $publicOnly,
    ) {
    }

    /**
     * @param array<string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $statuses = [];
        if (isset($raw['statuses']) && is_array($raw['statuses'])) {
            foreach ($raw['statuses'] as $s) {
                if (is_string($s) && $s !== '') {
                    $statuses[] = strtolower(trim($s));
                }
            }
        }
        if ($statuses === []) {
            $statuses = ['published'];
        }

        $termId = isset($raw['taxonomy_term_id']) ? (int) $raw['taxonomy_term_id'] : 0;
        $termId = $termId > 0 ? $termId : null;

        $filters = [];
        if (isset($raw['field_filters']) && is_array($raw['field_filters'])) {
            foreach ($raw['field_filters'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $key = isset($row['field_key']) && is_string($row['field_key']) ? trim($row['field_key']) : '';
                $op = isset($row['op']) && is_string($row['op']) ? trim($row['op']) : '';
                $value = isset($row['value']) && is_string($row['value']) ? trim($row['value']) : '';
                if ($key === '' || $op === '' || $value === '') {
                    continue;
                }
                $filters[] = ['field_key' => $key, 'op' => $op, 'value' => $value];
                if (count($filters) >= self::MAX_FIELD_FILTERS) {
                    break;
                }
            }
        }

        $sort = isset($raw['sort']) && is_array($raw['sort']) ? $raw['sort'] : [];
        $sortField = isset($sort['field']) && is_string($sort['field']) ? trim($sort['field']) : 'published_at';
        $sortDir = isset($sort['direction']) && is_string($sort['direction']) ? strtolower(trim($sort['direction'])) : 'desc';
        if (!in_array($sortDir, ['asc', 'desc'], true)) {
            $sortDir = 'desc';
        }

        $perPage = isset($raw['per_page']) ? (int) $raw['per_page'] : self::PER_PAGE_DEFAULT;
        $perPage = max(1, min(self::PER_PAGE_MAX, $perPage));

        $publicOnly = !empty($raw['public_only']);

        return new self($statuses, $termId, $filters, $sortField, $sortDir, $perPage, $publicOnly);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'statuses' => $this->statuses,
            'field_filters' => $this->fieldFilters,
            'sort' => [
                'field' => $this->sortField,
                'direction' => $this->sortDirection,
            ],
            'per_page' => $this->perPage,
            'public_only' => $this->publicOnly,
        ];
        if ($this->taxonomyTermId !== null) {
            $payload['taxonomy_term_id'] = $this->taxonomyTermId;
        }

        return $payload;
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
