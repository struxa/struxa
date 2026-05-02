<?php

declare(strict_types=1);

namespace App\Menu;

final class MenuValidator
{
    /**
     * @param array<string, mixed> $body
     * @return array{errors: array<string, string>, values: array{name: string, location: string}}
     */
    public function validate(array $body): array
    {
        $errors = [];

        $name = $this->str($body, 'name');
        if ($name === '') {
            $errors['name'] = 'Menu name is required.';
        } elseif (mb_strlen($name) > 160) {
            $errors['name'] = 'Menu name is too long.';
        }

        $location = $this->str($body, 'location');
        if (!in_array($location, ['header', 'footer'], true)) {
            $errors['location'] = 'Choose header or footer.';
        }

        return [
            'errors' => $errors,
            'values' => ['name' => $name, 'location' => $location],
        ];
    }

    private function str(array $body, string $key): string
    {
        $v = $body[$key] ?? '';

        return trim(is_string($v) ? str_replace("\0", '', $v) : '');
    }
}
