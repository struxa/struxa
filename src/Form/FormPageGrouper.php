<?php

declare(strict_types=1);

namespace App\Form;

/** Groups fields into pages for multi-page forms. */
final class FormPageGrouper
{
    /**
     * @param list<array<string, mixed>> $fields
     *
     * @return list<array{page: int, title: string, fields: list<array<string, mixed>>}>
     */
    public static function group(array $fields): array
    {
        $pages = [];
        foreach ($fields as $field) {
            $type = (string) ($field['field_type'] ?? '');
            if ($type === FormFieldType::PAGE_BREAK || $type === FormFieldType::HONEYPOT) {
                continue;
            }
            $page = max(1, (int) ($field['page_number'] ?? 1));
            if (!isset($pages[$page])) {
                $pages[$page] = ['page' => $page, 'title' => 'Page ' . $page, 'fields' => []];
            }
            $pages[$page]['fields'][] = $field;
        }

        if ($pages === []) {
            return [['page' => 1, 'title' => 'Page 1', 'fields' => []]];
        }

        ksort($pages);

        return array_values($pages);
    }

    /**
     * @param list<array<string, mixed>> $fields
     *
     * @return list<array{page: int, title: string}>
     */
    public static function pageTitles(array $fields): array
    {
        $titles = [];
        $currentPage = 1;
        foreach ($fields as $field) {
            if (($field['field_type'] ?? '') === FormFieldType::PAGE_BREAK) {
                $currentPage = max(1, (int) ($field['page_number'] ?? $currentPage + 1));
                $label = trim((string) ($field['label'] ?? ''));
                $titles[$currentPage] = $label !== '' ? $label : 'Page ' . $currentPage;
            }
        }

        return $titles;
    }

    public static function maxPageNumber(array $fields): int
    {
        $max = 1;
        foreach ($fields as $field) {
            $max = max($max, (int) ($field['page_number'] ?? 1));
        }

        return $max;
    }
}
