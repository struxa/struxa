<?php

declare(strict_types=1);

namespace App\Form;

/** Starter form blueprints (WPForms-style templates). */
final class FormTemplateCatalog
{
    /**
     * @return array<string, array{name: string, description: string, fields: list<array<string, mixed>>}>
     */
    public static function all(): array
    {
        return [
            'blank' => [
                'name' => 'Blank form',
                'description' => 'Start from scratch with no fields.',
                'fields' => [],
            ],
            'contact' => [
                'name' => 'Simple contact form',
                'description' => 'Name, email, subject, and message — ideal for a contact page.',
                'fields' => [
                    ['field_key' => 'name', 'field_type' => FormFieldType::TEXT, 'label' => 'Name', 'required' => 1, 'sort_order' => 10],
                    ['field_key' => 'email', 'field_type' => FormFieldType::EMAIL, 'label' => 'Email', 'required' => 1, 'sort_order' => 20],
                    ['field_key' => 'subject', 'field_type' => FormFieldType::TEXT, 'label' => 'Subject', 'required' => 0, 'sort_order' => 30],
                    ['field_key' => 'message', 'field_type' => FormFieldType::TEXTAREA, 'label' => 'Message', 'required' => 1, 'sort_order' => 40],
                ],
            ],
            'newsletter' => [
                'name' => 'Newsletter signup',
                'description' => 'Collect email addresses for your mailing list.',
                'fields' => [
                    ['field_key' => 'email', 'field_type' => FormFieldType::EMAIL, 'label' => 'Email address', 'placeholder' => 'you@example.com', 'required' => 1, 'sort_order' => 10],
                ],
            ],
            'support' => [
                'name' => 'Support request',
                'description' => 'Help desk intake with priority and details.',
                'fields' => [
                    ['field_key' => 'name', 'field_type' => FormFieldType::TEXT, 'label' => 'Your name', 'required' => 1, 'sort_order' => 10],
                    ['field_key' => 'email', 'field_type' => FormFieldType::EMAIL, 'label' => 'Email', 'required' => 1, 'sort_order' => 20],
                    ['field_key' => 'priority', 'field_type' => FormFieldType::SELECT, 'label' => 'Priority', 'required' => 1, 'sort_order' => 30,
                        'options_json' => ['Low', 'Normal', 'Urgent']],
                    ['field_key' => 'details', 'field_type' => FormFieldType::TEXTAREA, 'label' => 'How can we help?', 'required' => 1, 'sort_order' => 40],
                ],
            ],
        ];
    }

    public static function isValid(string $key): bool
    {
        return isset(self::all()[$key]);
    }
}
