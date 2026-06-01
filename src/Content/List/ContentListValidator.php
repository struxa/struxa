<?php

declare(strict_types=1);

namespace App\Content\List;

use App\Content\ContentFieldRepository;
use App\Content\ContentSlugger;
use App\Content\ContentTypeRepository;
use App\Taxonomy\TaxonomyTermRepository;

final class ContentListValidator
{
    private const ENTRY_SORT_FIELDS = ['published_at', 'updated_at', 'created_at', 'title', 'slug'];

    private const NUMBER_OPS = ['eq', 'neq', 'gt', 'gte', 'lt', 'lte'];

    private const TEXT_OPS = ['eq', 'contains'];

    public function __construct(
        private readonly ContentTypeRepository $types,
        private readonly ContentFieldRepository $fields,
        private readonly TaxonomyTermRepository $terms,
        private readonly ContentListRepository $lists,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     * @return array{ok: true, clean: array<string, mixed>}|array{ok: false, error: string}
     */
    public function validateSave(array $body, ?int $exceptId = null): array
    {
        $name = isset($body['name']) && is_string($body['name']) ? trim($body['name']) : '';
        if ($name === '') {
            return ['ok' => false, 'error' => 'Name is required.'];
        }
        if (mb_strlen($name) > 160) {
            return ['ok' => false, 'error' => 'Name is too long (160 characters max).'];
        }

        $slugRaw = isset($body['slug']) && is_string($body['slug']) ? trim($body['slug']) : '';
        $slug = $slugRaw !== '' ? ContentSlugger::slugify($slugRaw) : ContentSlugger::slugify($name);
        if ($slug === '' || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1) {
            return ['ok' => false, 'error' => 'Slug must use lowercase letters, numbers, and hyphens.'];
        }
        if ($this->lists->slugExists($slug, $exceptId)) {
            return ['ok' => false, 'error' => 'That slug is already used by another list.'];
        }

        $typeId = isset($body['content_type_id']) ? (int) $body['content_type_id'] : 0;
        $type = $typeId > 0 ? $this->types->findById($typeId) : null;
        if ($type === null) {
            return ['ok' => false, 'error' => 'Choose a content type.'];
        }

        $statuses = [];
        if (isset($body['statuses']) && is_array($body['statuses'])) {
            foreach ($body['statuses'] as $s) {
                if (!is_string($s)) {
                    continue;
                }
                $s = strtolower(trim($s));
                if (in_array($s, ['draft', 'in_review', 'approved', 'published', 'archived'], true)) {
                    $statuses[] = $s;
                }
            }
        }
        $statuses = array_values(array_unique($statuses));
        if ($statuses === []) {
            $statuses = ['published'];
        }

        $termId = isset($body['taxonomy_term_id']) ? (int) $body['taxonomy_term_id'] : 0;
        if ($termId > 0 && $this->terms->findById($termId) === null) {
            return ['ok' => false, 'error' => 'Selected taxonomy term was not found.'];
        }
        $termId = $termId > 0 ? $termId : null;

        $fieldFilters = [];
        $filterKeys = isset($body['filter_field_key']) && is_array($body['filter_field_key']) ? $body['filter_field_key'] : [];
        $filterOps = isset($body['filter_op']) && is_array($body['filter_op']) ? $body['filter_op'] : [];
        $filterVals = isset($body['filter_value']) && is_array($body['filter_value']) ? $body['filter_value'] : [];
        $typeFields = $this->fields->forTypeOrdered($type->id);
        $fieldsByKey = [];
        foreach ($typeFields as $f) {
            $fieldsByKey[$f->fieldKey] = $f;
        }
        $count = max(count($filterKeys), count($filterOps), count($filterVals));
        for ($i = 0; $i < $count && count($fieldFilters) < ContentListDefinition::MAX_FIELD_FILTERS; ++$i) {
            $key = isset($filterKeys[$i]) && is_string($filterKeys[$i]) ? trim($filterKeys[$i]) : '';
            $op = isset($filterOps[$i]) && is_string($filterOps[$i]) ? trim($filterOps[$i]) : '';
            $value = isset($filterVals[$i]) && is_string($filterVals[$i]) ? trim($filterVals[$i]) : '';
            if ($key === '' && $op === '' && $value === '') {
                continue;
            }
            if ($key === '' || !isset($fieldsByKey[$key])) {
                return ['ok' => false, 'error' => 'Each field filter must use a valid field on this content type.'];
            }
            $field = $fieldsByKey[$key];
            $allowedOps = match ($field->fieldType) {
                'number' => self::NUMBER_OPS,
                'boolean' => ['eq'],
                'text', 'textarea', 'richtext', 'url', 'select' => self::TEXT_OPS,
                default => self::TEXT_OPS,
            };
            if (!in_array($op, $allowedOps, true)) {
                return ['ok' => false, 'error' => sprintf('Invalid operator for field "%s".', $key)];
            }
            if ($value === '') {
                return ['ok' => false, 'error' => sprintf('Enter a value for field filter "%s".', $key)];
            }
            if ($field->fieldType === 'number' && !is_numeric($value)) {
                return ['ok' => false, 'error' => sprintf('"%s" filter value must be a number.', $key)];
            }
            $fieldFilters[] = ['field_key' => $key, 'op' => $op, 'value' => $value];
        }

        $sortField = isset($body['sort_field']) && is_string($body['sort_field']) ? trim($body['sort_field']) : 'published_at';
        $sortDir = isset($body['sort_direction']) && is_string($body['sort_direction']) ? strtolower(trim($body['sort_direction'])) : 'desc';
        if (!in_array($sortDir, ['asc', 'desc'], true)) {
            $sortDir = 'desc';
        }
        if (str_starts_with($sortField, 'field:')) {
            $fk = substr($sortField, 6);
            if ($fk === '' || !isset($fieldsByKey[$fk])) {
                return ['ok' => false, 'error' => 'Invalid sort field.'];
            }
        } elseif (!in_array($sortField, self::ENTRY_SORT_FIELDS, true)) {
            return ['ok' => false, 'error' => 'Invalid sort field.'];
        }

        $perPage = isset($body['per_page']) ? (int) $body['per_page'] : ContentListDefinition::PER_PAGE_DEFAULT;
        $perPage = max(1, min(ContentListDefinition::PER_PAGE_MAX, $perPage));

        $publicOnly = !empty($body['public_only']);
        $exposePublic = !empty($body['expose_public_page']);
        $exposeApi = array_key_exists('expose_api', $body) ? !empty($body['expose_api']) : true;

        $definition = new ContentListDefinition(
            $statuses,
            $termId,
            $fieldFilters,
            $sortField,
            $sortDir,
            $perPage,
            $publicOnly || $exposePublic,
        );

        $description = isset($body['description']) && is_string($body['description']) ? trim($body['description']) : '';

        return [
            'ok' => true,
            'clean' => [
                'name' => $name,
                'slug' => $slug,
                'description' => $description !== '' ? $description : null,
                'content_type_id' => $type->id,
                'definition_json' => $definition->toJson(),
                'is_active' => !empty($body['is_active']),
                'expose_public_page' => $exposePublic,
                'expose_api' => $exposeApi,
            ],
        ];
    }
}
