<?php

declare(strict_types=1);

namespace App\Content\List;

use App\Content\ContentFieldRepository;
use PDO;

/**
 * Executes saved list definitions against cms_content_entries (+ values, taxonomy).
 */
final class ContentListQueryRunner
{
    private const PUBLIC_ENTRY_WHERE = "e.deleted_at IS NULL AND e.status = 'published' AND (e.published_at IS NULL OR e.published_at <= NOW(6))";

    public function __construct(
        private readonly PDO $pdo,
        private readonly ContentFieldRepository $fields,
    ) {
    }

    public function count(int $contentTypeId, ContentListDefinition $def, bool $forcePublicVisibility): int
    {
        [$sql, $params] = $this->buildSelectSql($contentTypeId, $def, $forcePublicVisibility, true);

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchPage(int $contentTypeId, ContentListDefinition $def, bool $forcePublicVisibility, int $page): array
    {
        $page = max(1, $page);
        $perPage = $def->perPage;
        $offset = ($page - 1) * $perPage;

        [$sql, $params] = $this->buildSelectSql($contentTypeId, $def, $forcePublicVisibility, false);
        $sql .= ' LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[] = $row;
        }

        return $out;
    }

    /**
     * @return array{0: string, 1: list<int|string>}
     */
    private function buildSelectSql(int $contentTypeId, ContentListDefinition $def, bool $forcePublicVisibility, bool $countOnly): array
    {
        $params = [$contentTypeId];
        $joins = '';
        $where = ['e.content_type_id = ?', 'e.deleted_at IS NULL'];

        if ($forcePublicVisibility || $def->publicOnly) {
            $where = ['e.content_type_id = ?', self::PUBLIC_ENTRY_WHERE];
            $params = [$contentTypeId];
        } else {
            $statuses = array_values(array_unique($def->statuses));
            if ($statuses === []) {
                $statuses = ['published'];
            }
            $ph = implode(',', array_fill(0, count($statuses), '?'));
            $where[] = 'e.status IN (' . $ph . ')';
            foreach ($statuses as $s) {
                $params[] = $s;
            }
        }

        if ($def->taxonomyTermId !== null) {
            $joins .= ' INNER JOIN cms_content_entry_taxonomy_terms j_tax ON j_tax.content_entry_id = e.id AND j_tax.taxonomy_term_id = ?';
            $params[] = $def->taxonomyTermId;
        }

        $fieldMap = [];
        foreach ($this->fields->forTypeOrdered($contentTypeId) as $field) {
            $fieldMap[$field->fieldKey] = $field;
        }

        $aliasN = 0;
        foreach ($def->fieldFilters as $filter) {
            $key = $filter['field_key'];
            if (!isset($fieldMap[$key])) {
                continue;
            }
            $fieldId = $fieldMap[$key]->id;
            $alias = 'fv' . $aliasN;
            ++$aliasN;
            $joins .= ' INNER JOIN cms_content_entry_values ' . $alias
                . ' ON ' . $alias . '.content_entry_id = e.id AND ' . $alias . '.field_id = ?';
            $params[] = $fieldId;

            $col = $alias . '.value_longtext';
            $type = $fieldMap[$key]->fieldType;
            $op = $filter['op'];
            $value = $filter['value'];

            if ($type === 'number') {
                $numCol = 'CAST(' . $col . ' AS DECIMAL(16,4))';
                $numVal = (float) $value;
                $where[] = match ($op) {
                    'eq' => $numCol . ' = ?',
                    'neq' => $numCol . ' <> ?',
                    'gt' => $numCol . ' > ?',
                    'gte' => $numCol . ' >= ?',
                    'lt' => $numCol . ' < ?',
                    'lte' => $numCol . ' <= ?',
                    default => '1 = 0',
                };
                $params[] = $numVal;
            } elseif ($type === 'boolean') {
                $boolVal = in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true) ? '1' : '0';
                $where[] = $col . ' = ?';
                $params[] = $boolVal;
            } else {
                $where[] = match ($op) {
                    'eq' => $col . ' = ?',
                    'contains' => $col . ' LIKE ?',
                    default => '1 = 0',
                };
                $params[] = $op === 'contains' ? '%' . $this->escapeLike($value) . '%' : $value;
            }
        }

        $orderSql = '';
        if (!$countOnly) {
            $orderSql = $this->orderByClause($def, $fieldMap, $joins);
        }

        $select = $countOnly ? 'SELECT COUNT(DISTINCT e.id)' : 'SELECT DISTINCT e.*';
        $sql = $select . ' FROM cms_content_entries e' . $joins . ' WHERE ' . implode(' AND ', $where) . $orderSql;

        return [$sql, $params];
    }

    /**
     * @param array<string, \App\Content\ContentField> $fieldMap
     */
    private function orderByClause(ContentListDefinition $def, array $fieldMap, string &$joins): string
    {
        $dir = $def->sortDirection === 'asc' ? 'ASC' : 'DESC';
        $field = $def->sortField;

        if (str_starts_with($field, 'field:')) {
            $key = substr($field, 6);
            if ($key === '' || !isset($fieldMap[$key])) {
                return ' ORDER BY e.published_at DESC, e.id DESC';
            }
            $f = $fieldMap[$key];
            $alias = 'fsort';
            if (!str_contains($joins, ' ' . $alias . ' ')) {
                $joins .= ' LEFT JOIN cms_content_entry_values ' . $alias
                    . ' ON ' . $alias . '.content_entry_id = e.id AND ' . $alias . '.field_id = ' . (int) $f->id;
            }
            if ($f->fieldType === 'number') {
                return ' ORDER BY CAST(' . $alias . '.value_longtext AS DECIMAL(16,4)) ' . $dir . ', e.id DESC';
            }

            return ' ORDER BY ' . $alias . '.value_longtext ' . $dir . ', e.id DESC';
        }

        $allowed = ['published_at', 'updated_at', 'created_at', 'title', 'slug'];
        if (!in_array($field, $allowed, true)) {
            $field = 'published_at';
        }

        return ' ORDER BY e.' . $field . ' ' . $dir . ', e.id DESC';
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
