<?php

declare(strict_types=1);

namespace App\Form;

/** Evaluates show/hide conditional rules for form fields. */
final class FormConditionalLogic
{
    /**
     * @param array<string, mixed> $field
     * @param array<string, mixed> $values field_key => scalar or list
     */
    public static function isVisible(array $field, array $values): bool
    {
        $rules = self::rulesFor($field);
        if ($rules === null) {
            return true;
        }

        $action = ($rules['action'] ?? 'show') === 'hide' ? 'hide' : 'show';
        $operator = ($rules['operator'] ?? 'all') === 'any' ? 'any' : 'all';
        $matches = [];
        foreach ($rules['rules'] as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $matches[] = self::ruleMatches($rule, $values);
        }
        if ($matches === []) {
            return true;
        }

        $matched = $operator === 'any'
            ? in_array(true, $matches, true)
            : !in_array(false, $matches, true);

        return $action === 'show' ? $matched : !$matched;
    }

    /**
     * @param array<string, mixed> $field
     *
     * @return array{action: string, operator: string, rules: list<array<string, mixed>>}|null
     */
    public static function rulesFor(array $field): ?array
    {
        $raw = $field['conditional'] ?? null;
        if (!is_array($raw) || empty($raw['enabled'])) {
            return null;
        }
        $rules = $raw['rules'] ?? [];
        if (!is_array($rules) || $rules === []) {
            return null;
        }

        $out = [];
        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $key = trim((string) ($rule['field_key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $out[] = [
                'field_key' => $key,
                'operator' => (string) ($rule['operator'] ?? 'is'),
                'value' => (string) ($rule['value'] ?? ''),
            ];
        }

        if ($out === []) {
            return null;
        }

        return [
            'action' => (string) ($raw['action'] ?? 'show'),
            'operator' => (string) ($raw['operator'] ?? 'all'),
            'rules' => $out,
        ];
    }

    /**
     * @param array<string, mixed> $rule
     * @param array<string, mixed> $values
     */
    private static function ruleMatches(array $rule, array $values): bool
    {
        $key = (string) ($rule['field_key'] ?? '');
        $op = (string) ($rule['operator'] ?? 'is');
        $expected = (string) ($rule['value'] ?? '');
        $actual = self::valueAsString($values[$key] ?? '');

        return match ($op) {
            'is' => strcasecmp($actual, $expected) === 0,
            'is_not' => strcasecmp($actual, $expected) !== 0,
            'contains' => $actual !== '' && stripos($actual, $expected) !== false,
            'not_contains' => $actual === '' || stripos($actual, $expected) === false,
            'empty' => trim($actual) === '',
            'not_empty' => trim($actual) !== '',
            'greater' => is_numeric($actual) && is_numeric($expected) && (float) $actual > (float) $expected,
            'less' => is_numeric($actual) && is_numeric($expected) && (float) $actual < (float) $expected,
            default => false,
        };
    }

    private static function valueAsString(mixed $value): string
    {
        if (is_array($value)) {
            return implode(', ', array_map('strval', $value));
        }
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        return trim((string) $value);
    }
}
