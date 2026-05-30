<?php

declare(strict_types=1);

namespace App\Form;

/** Builds public-facing form context for Twig. */
final class FormRenderer
{
    /**
     * @param array<string, mixed> $form
     * @param list<array<string, mixed>> $fields
     *
     * @return array<string, mixed> Twig context for form partial
     */
    public static function context(array $form, array $fields, string $actionUrl, ?string $returnTo = null): array
    {
        $inputFields = [];
        $honeypotFields = [];
        $pageTitles = FormPageGrouper::pageTitles($fields);
        foreach ($fields as $field) {
            $type = (string) ($field['field_type'] ?? '');
            if ($type === FormFieldType::HONEYPOT) {
                if (!empty($form['honeypot_enabled'])) {
                    $honeypotFields[] = self::fieldForClient($field);
                }
                continue;
            }
            if ($type === FormFieldType::PAGE_BREAK) {
                continue;
            }
            $inputFields[] = self::fieldForClient($field);
        }

        $pages = FormPageGrouper::group($fields);
        foreach ($pages as &$page) {
            $title = $pageTitles[$page['page']] ?? null;
            if (is_string($title) && $title !== '') {
                $page['title'] = $title;
            }
            $page['fields'] = array_map([self::class, 'fieldForClient'], $page['fields']);
        }
        unset($page);

        return [
            'form' => $form,
            'fields' => $inputFields,
            'honeypot_fields' => $honeypotFields,
            'form_pages' => $pages,
            'form_is_multipage' => count($pages) > 1,
            'form_is_quiz' => ($form['form_type'] ?? 'standard') === 'quiz',
            'action_url' => $actionUrl,
            'return_to' => $returnTo,
        ];
    }

    /**
     * @param array<string, mixed> $field
     *
     * @return array<string, mixed>
     */
    private static function fieldForClient(array $field): array
    {
        $rules = FormConditionalLogic::rulesFor($field);

        return [
            'id' => $field['id'] ?? null,
            'field_key' => $field['field_key'] ?? '',
            'field_type' => $field['field_type'] ?? 'text',
            'label' => $field['label'] ?? '',
            'placeholder' => $field['placeholder'] ?? '',
            'help_text' => $field['help_text'] ?? '',
            'required' => !empty($field['required']),
            'options' => $field['options'] ?? [],
            'page_number' => (int) ($field['page_number'] ?? 1),
            'settings' => $field['settings'] ?? [],
            'conditional' => $rules,
        ];
    }
}
