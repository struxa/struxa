<?php

declare(strict_types=1);

namespace App\Form;

/** Builds public-facing form HTML for Twig-free embedding when needed. */
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
        $visible = [];
        foreach ($fields as $field) {
            if (($field['field_type'] ?? '') === FormFieldType::HONEYPOT && empty($form['honeypot_enabled'])) {
                continue;
            }
            $visible[] = $field;
        }

        return [
            'form' => $form,
            'fields' => $visible,
            'action_url' => $actionUrl,
            'return_to' => $returnTo,
        ];
    }
}
