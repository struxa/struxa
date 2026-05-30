<?php

declare(strict_types=1);

namespace App\Form;

/** Supported public form field types. */
final class FormFieldType
{
    public const TEXT = 'text';
    public const EMAIL = 'email';
    public const TEXTAREA = 'textarea';
    public const SELECT = 'select';
    public const CHECKBOX = 'checkbox';
    public const CHECKBOXES = 'checkboxes';
    public const RADIO = 'radio';
    public const NUMBER = 'number';
    public const PHONE = 'phone';
    public const URL = 'url';
    public const FILE = 'file';
    public const HIDDEN = 'hidden';
    public const HONEYPOT = 'honeypot';
    public const PAGE_BREAK = 'page_break';

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::TEXT => 'Single line text',
            self::EMAIL => 'Email',
            self::TEXTAREA => 'Paragraph text',
            self::SELECT => 'Dropdown',
            self::CHECKBOX => 'Checkbox',
            self::CHECKBOXES => 'Checkboxes',
            self::RADIO => 'Multiple choice',
            self::NUMBER => 'Number',
            self::PHONE => 'Phone',
            self::URL => 'Website / URL',
            self::FILE => 'File upload',
            self::PAGE_BREAK => 'Page break',
            self::HIDDEN => 'Hidden field',
        ];
    }

    /**
     * @return list<string>
     */
    public static function choiceTypes(): array
    {
        return [self::SELECT, self::RADIO, self::CHECKBOXES];
    }

    /**
     * @return list<string>
     */
    public static function quizScorableTypes(): array
    {
        return [self::SELECT, self::RADIO, self::CHECKBOXES];
    }

    public static function isValid(string $type): bool
    {
        return array_key_exists($type, self::labels()) || $type === self::HONEYPOT;
    }

    public static function isInputType(string $type): bool
    {
        return !in_array($type, [self::HONEYPOT, self::PAGE_BREAK, self::HIDDEN], true);
    }
}
