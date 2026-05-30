<?php

declare(strict_types=1);

namespace App\Form;

use Psr\Http\Message\UploadedFileInterface;

final class FormValidator
{
    /**
     * @param array<string, mixed> $body
     * @param list<array<string, mixed>> $fields
     * @param array<string, UploadedFileInterface> $uploadedFiles field_key => file
     *
     * @return array{ok: true, clean: list<array{field_id: int|null, field_key: string, value_text: string|null, value_file_path?: string|null>}}|array{ok: false, error: string}
     */
    public static function validateSubmission(
        array $body,
        array $fields,
        bool $honeypotEnabled,
        array $uploadedFiles = [],
        ?FormFileUploadService $uploader = null,
        ?int $formId = null,
    ): array {
        $valueMap = self::bodyToValueMap($body);
        $clean = [];

        foreach ($fields as $field) {
            $type = (string) ($field['field_type'] ?? '');
            $key = (string) ($field['field_key'] ?? '');

            if ($type === FormFieldType::HONEYPOT) {
                if ($honeypotEnabled && self::readValue($body, $key) !== '') {
                    return ['ok' => false, 'error' => 'Submission rejected.'];
                }
                continue;
            }

            if (!FormFieldType::isInputType($type)) {
                continue;
            }

            if (!FormConditionalLogic::isVisible($field, $valueMap)) {
                continue;
            }

            $required = !empty($field['required']);

            if ($type === FormFieldType::FILE) {
                $file = $uploadedFiles[$key] ?? null;
                if (!$file instanceof UploadedFileInterface || $file->getError() === UPLOAD_ERR_NO_FILE) {
                    if ($required) {
                        return ['ok' => false, 'error' => self::labelFor($field) . ' is required.'];
                    }
                    continue;
                }
                if ($uploader === null || $formId === null) {
                    return ['ok' => false, 'error' => 'File uploads are not available.'];
                }
                $settings = is_array($field['settings'] ?? null) ? $field['settings'] : [];
                $stored = $uploader->store($file, $formId, $settings);
                if ($stored['ok'] !== true) {
                    return ['ok' => false, 'error' => $stored['error']];
                }
                $clean[] = [
                    'field_id' => isset($field['id']) ? (int) $field['id'] : null,
                    'field_key' => $key,
                    'value_text' => $stored['original'],
                    'value_file_path' => $stored['path'],
                ];
                continue;
            }

            if ($type === FormFieldType::CHECKBOXES) {
                $rawList = self::readArrayValue($body, $key);
                if ($required && $rawList === []) {
                    return ['ok' => false, 'error' => self::labelFor($field) . ' is required.'];
                }
                $clean[] = [
                    'field_id' => isset($field['id']) ? (int) $field['id'] : null,
                    'field_key' => $key,
                    'value_text' => $rawList === [] ? null : implode(', ', $rawList),
                ];
                continue;
            }

            if ($type === FormFieldType::CHECKBOX) {
                $checked = self::readCheckbox($body, $key);
                if ($required && !$checked) {
                    return ['ok' => false, 'error' => self::labelFor($field) . ' is required.'];
                }
                $clean[] = [
                    'field_id' => isset($field['id']) ? (int) $field['id'] : null,
                    'field_key' => $key,
                    'value_text' => $checked ? 'Yes' : 'No',
                ];
                continue;
            }

            $raw = self::readValue($body, $key);
            if ($required && trim($raw) === '') {
                return ['ok' => false, 'error' => self::labelFor($field) . ' is required.'];
            }

            if ($raw !== '') {
                $err = self::validateType($type, $raw, self::labelFor($field));
                if ($err !== null) {
                    return ['ok' => false, 'error' => $err];
                }
            }

            $clean[] = [
                'field_id' => isset($field['id']) ? (int) $field['id'] : null,
                'field_key' => $key,
                'value_text' => $raw === '' ? null : $raw,
            ];
        }

        return ['ok' => true, 'clean' => $clean];
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function validateFormSettings(array $body, ?int $excludeId, \PDO $pdo): array
    {
        $name = isset($body['name']) && is_string($body['name']) ? trim($body['name']) : '';
        if ($name === '' || mb_strlen($name) > 200) {
            return ['ok' => false, 'error' => 'Form name is required (max 200 characters).'];
        }

        $slugInput = isset($body['slug']) && is_string($body['slug']) ? trim($body['slug']) : '';
        $slug = $slugInput !== '' ? FormSlugger::fromName($slugInput) : FormSlugger::fromName($name);
        if ($slug === '' || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            return ['ok' => false, 'error' => 'Slug must use lowercase letters, numbers, and hyphens.'];
        }

        $slug = FormSlugger::ensureUnique($pdo, $slug, $excludeId);

        $status = isset($body['status']) && is_string($body['status']) ? trim($body['status']) : 'draft';
        if (!in_array($status, ['draft', 'published'], true)) {
            $status = 'draft';
        }

        $formType = isset($body['form_type']) && is_string($body['form_type']) ? trim($body['form_type']) : 'standard';
        if (!in_array($formType, ['standard', 'quiz'], true)) {
            $formType = 'standard';
        }

        $confirmationType = isset($body['confirmation_type']) && is_string($body['confirmation_type'])
            ? trim($body['confirmation_type']) : 'message';
        if (!in_array($confirmationType, ['message', 'redirect'], true)) {
            $confirmationType = 'message';
        }

        $redirect = isset($body['confirmation_redirect_url']) && is_string($body['confirmation_redirect_url'])
            ? trim($body['confirmation_redirect_url']) : '';
        if ($confirmationType === 'redirect' && ($redirect === '' || !str_starts_with($redirect, '/'))) {
            return ['ok' => false, 'error' => 'Redirect URL must start with / when using redirect confirmation.'];
        }

        $notifyEmails = isset($body['notify_emails']) && is_string($body['notify_emails'])
            ? trim($body['notify_emails']) : '';
        $emailList = self::parseEmails($notifyEmails);

        $settings = [
            'quiz_pass_percent' => max(0, min(100, (int) ($body['quiz_pass_percent'] ?? 70))),
            'quiz_show_score' => !empty($body['quiz_show_score']),
            'quiz_pass_message' => self::optionalString($body['quiz_pass_message'] ?? null, 500) ?? 'Well done!',
            'quiz_fail_message' => self::optionalString($body['quiz_fail_message'] ?? null, 500) ?? 'Try again.',
        ];

        return [
            'ok' => true,
            'clean' => [
                'name' => $name,
                'slug' => $slug,
                'description' => isset($body['description']) && is_string($body['description']) ? trim($body['description']) : null,
                'status' => $status,
                'form_type' => $formType,
                'submit_label' => self::clipString($body['submit_label'] ?? 'Submit', 80, 'Submit'),
                'next_label' => self::clipString($body['next_label'] ?? 'Next', 80, 'Next'),
                'prev_label' => self::clipString($body['prev_label'] ?? 'Previous', 80, 'Previous'),
                'confirmation_type' => $confirmationType,
                'confirmation_message' => isset($body['confirmation_message']) && is_string($body['confirmation_message'])
                    ? trim($body['confirmation_message']) : null,
                'confirmation_redirect_url' => $redirect !== '' ? $redirect : null,
                'honeypot_enabled' => !empty($body['honeypot_enabled']),
                'notify_enabled' => !empty($body['notify_enabled']),
                'notify_emails' => $emailList === [] ? null : implode(', ', $emailList),
                'notify_subject' => self::clipString($body['notify_subject'] ?? '', 255, 'New form submission'),
                'settings' => $settings,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function validateFieldInput(array $body, int $formId, bool $isQuiz = false): array
    {
        $parsed = self::parseFieldCore($body, $formId);
        if ($parsed['ok'] !== true) {
            return $parsed;
        }

        $parsed['clean']['settings'] = self::parseFieldSettings($body, (string) $parsed['clean']['field_type'], $isQuiz);
        $parsed['clean']['conditional'] = self::parseConditional($body);

        return $parsed;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array{ok: true, clean: array<string, mixed>}|array{ok: false, error: string}
     */
    private static function parseFieldCore(array $body, int $formId): array
    {
        $label = isset($body['label']) && is_string($body['label']) ? trim($body['label']) : '';
        if ($label === '' || mb_strlen($label) > 200) {
            return ['ok' => false, 'error' => 'Field label is required.'];
        }

        $type = isset($body['field_type']) && is_string($body['field_type']) ? trim($body['field_type']) : FormFieldType::TEXT;
        if (!FormFieldType::isValid($type)) {
            return ['ok' => false, 'error' => 'Invalid field type.'];
        }

        $keyInput = isset($body['field_key']) && is_string($body['field_key']) ? trim($body['field_key']) : '';
        $key = $keyInput !== '' ? FormSlugger::fromName($keyInput) : FormSlugger::fromName($label);
        if ($key === '' || !preg_match('/^[a-z][a-z0-9_]*$/', str_replace('-', '_', $key))) {
            $key = 'field_' . bin2hex(random_bytes(3));
        }
        $key = str_replace('-', '_', $key);

        $options = isset($body['options']) && is_string($body['options']) ? $body['options'] : null;
        if (in_array($type, FormFieldType::choiceTypes(), true) && ($options === null || trim($options) === '')) {
            return ['ok' => false, 'error' => 'Choice fields need at least one option (one per line).'];
        }

        return [
            'ok' => true,
            'clean' => [
                'form_id' => $formId,
                'field_key' => $key,
                'field_type' => $type,
                'label' => $label,
                'placeholder' => self::optionalString($body['placeholder'] ?? null, 255),
                'help_text' => self::optionalString($body['help_text'] ?? null, 500),
                'required' => !empty($body['required']) && $type !== FormFieldType::PAGE_BREAK,
                'options_json' => $options,
                'sort_order' => isset($body['sort_order']) ? (int) $body['sort_order'] : 0,
                'page_number' => max(1, (int) ($body['page_number'] ?? 1)),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private static function parseFieldSettings(array $body, string $type, bool $isQuiz): array
    {
        $settings = [];
        if ($type === FormFieldType::FILE) {
            $settings['max_mb'] = max(1, min(20, (int) ($body['file_max_mb'] ?? 5)));
            $extRaw = isset($body['file_extensions']) && is_string($body['file_extensions']) ? $body['file_extensions'] : 'pdf,jpg,png,doc,docx';
            $settings['extensions'] = array_values(array_filter(array_map(
                static fn (string $e): string => strtolower(trim($e)),
                preg_split('/[,;\s]+/', $extRaw) ?: []
            )));
        }

        if ($isQuiz && in_array($type, FormFieldType::quizScorableTypes(), true)) {
            $settings['quiz_points'] = max(1, (int) ($body['quiz_points'] ?? 1));
            $correct = isset($body['quiz_correct']) && is_string($body['quiz_correct']) ? trim($body['quiz_correct']) : '';
            if ($type === FormFieldType::CHECKBOXES && $correct !== '') {
                $settings['quiz_correct'] = array_values(array_filter(array_map('trim', preg_split('/\R/', $correct) ?: [])));
            } elseif ($correct !== '') {
                $settings['quiz_correct'] = $correct;
            }
        }

        return $settings;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>|null
     */
    private static function parseConditional(array $body): ?array
    {
        if (empty($body['conditional_enabled'])) {
            return null;
        }

        $sourceKey = isset($body['conditional_field_key']) && is_string($body['conditional_field_key'])
            ? trim($body['conditional_field_key']) : '';
        if ($sourceKey === '') {
            return null;
        }

        return [
            'enabled' => true,
            'action' => ($body['conditional_action'] ?? 'show') === 'hide' ? 'hide' : 'show',
            'operator' => 'all',
            'rules' => [[
                'field_key' => $sourceKey,
                'operator' => is_string($body['conditional_operator'] ?? null) ? trim($body['conditional_operator']) : 'is',
                'value' => is_string($body['conditional_value'] ?? null) ? trim($body['conditional_value']) : '',
            ]],
        ];
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private static function bodyToValueMap(array $body): array
    {
        $map = [];
        foreach ($body as $key => $value) {
            if (!is_string($key) || str_starts_with($key, '_')) {
                continue;
            }
            if (is_array($value)) {
                $map[$key] = array_values(array_filter(array_map(static fn ($v): string => trim((string) $v), $value)));
            } elseif (is_string($value) || is_numeric($value)) {
                $map[$key] = trim((string) $value);
            }
        }

        return $map;
    }

    /**
     * @param array<string, mixed> $field
     */
    private static function labelFor(array $field): string
    {
        $label = trim((string) ($field['label'] ?? 'Field'));

        return $label !== '' ? $label : 'Field';
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function readValue(array $body, string $key): string
    {
        if (!isset($body[$key])) {
            return '';
        }
        $v = $body[$key];

        return is_string($v) ? trim($v) : (is_scalar($v) ? trim((string) $v) : '');
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return list<string>
     */
    private static function readArrayValue(array $body, string $key): array
    {
        if (!isset($body[$key])) {
            return [];
        }
        $v = $body[$key];
        if (!is_array($v)) {
            $s = is_string($v) ? trim($v) : '';

            return $s === '' ? [] : [$s];
        }
        $out = [];
        foreach ($v as $item) {
            if (is_string($item) && trim($item) !== '') {
                $out[] = trim($item);
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function readCheckbox(array $body, string $key): bool
    {
        if (!isset($body[$key])) {
            return false;
        }
        $v = $body[$key];

        return in_array($v, ['1', 'on', 'yes', 'true', true, 1], true);
    }

    private static function validateType(string $type, string $value, string $label): ?string
    {
        if ($type === FormFieldType::EMAIL && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return $label . ' must be a valid email address.';
        }
        if ($type === FormFieldType::URL && !filter_var($value, FILTER_VALIDATE_URL)) {
            return $label . ' must be a valid URL.';
        }
        if ($type === FormFieldType::NUMBER && !is_numeric($value)) {
            return $label . ' must be a number.';
        }
        if (mb_strlen($value) > 10000) {
            return $label . ' is too long.';
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function parseEmails(string $raw): array
    {
        if ($raw === '') {
            return [];
        }
        $parts = preg_split('/[,;\s]+/', $raw) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p !== '' && filter_var($p, FILTER_VALIDATE_EMAIL)) {
                $out[] = $p;
            }
        }

        return array_values(array_unique($out));
    }

    private static function clipString(mixed $value, int $max, string $fallback): string
    {
        if (!is_string($value)) {
            return $fallback;
        }
        $v = trim($value);

        return $v === '' ? $fallback : mb_substr($v, 0, $max);
    }

    private static function optionalString(mixed $value, int $max): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $v = trim($value);

        return $v === '' ? null : mb_substr($v, 0, $max);
    }
}
