<?php

declare(strict_types=1);

namespace App\Form;

/** Starter form blueprints (WPForms-style templates). */
final class FormTemplateCatalog
{
    /**
     * @return array<string, array{name: string, description: string, form?: array<string, mixed>, fields: list<array<string, mixed>>}>
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
            'application' => [
                'name' => 'Multi-page application',
                'description' => 'Two-step form with contact info and file upload.',
                'fields' => [
                    ['field_key' => 'name', 'field_type' => FormFieldType::TEXT, 'label' => 'Full name', 'required' => 1, 'sort_order' => 10, 'page_number' => 1],
                    ['field_key' => 'email', 'field_type' => FormFieldType::EMAIL, 'label' => 'Email', 'required' => 1, 'sort_order' => 20, 'page_number' => 1],
                    ['field_key' => '_page2', 'field_type' => FormFieldType::PAGE_BREAK, 'label' => 'Documents', 'required' => 0, 'sort_order' => 30, 'page_number' => 2],
                    ['field_key' => 'resume', 'field_type' => FormFieldType::FILE, 'label' => 'Résumé (PDF)', 'required' => 1, 'sort_order' => 40, 'page_number' => 2,
                        'settings' => ['max_mb' => 5, 'extensions' => ['pdf']]],
                    ['field_key' => 'cover_letter', 'field_type' => FormFieldType::TEXTAREA, 'label' => 'Cover letter', 'required' => 0, 'sort_order' => 50, 'page_number' => 2],
                ],
            ],
            'quiz' => [
                'name' => 'Knowledge quiz',
                'description' => 'Scored quiz with multiple-choice questions.',
                'form' => [
                    'form_type' => 'quiz',
                    'confirmation_message' => 'Thanks for taking the quiz!',
                    'settings' => [
                        'quiz_pass_percent' => 70,
                        'quiz_show_score' => true,
                        'quiz_pass_message' => 'Great job — you passed!',
                        'quiz_fail_message' => 'Keep studying and try again.',
                    ],
                ],
                'fields' => [
                    ['field_key' => 'q1', 'field_type' => FormFieldType::RADIO, 'label' => 'What is 2 + 2?', 'required' => 1, 'sort_order' => 10,
                        'options_json' => ['3', '4', '5'],
                        'settings' => ['quiz_points' => 10, 'quiz_correct' => '4']],
                    ['field_key' => 'q2', 'field_type' => FormFieldType::RADIO, 'label' => 'Capital of France?', 'required' => 1, 'sort_order' => 20,
                        'options_json' => ['London', 'Paris', 'Berlin'],
                        'settings' => ['quiz_points' => 10, 'quiz_correct' => 'Paris']],
                    ['field_key' => 'q3', 'field_type' => FormFieldType::SELECT, 'label' => 'Primary color of the sky on a clear day?', 'required' => 1, 'sort_order' => 30,
                        'options_json' => ['Red', 'Blue', 'Green'],
                        'settings' => ['quiz_points' => 10, 'quiz_correct' => 'Blue']],
                ],
            ],
        ];
    }

    public static function isValid(string $key): bool
    {
        return isset(self::all()[$key]);
    }
}
