<?php

declare(strict_types=1);

namespace App\Menu;

final class MenuItemValidator
{
    /**
     * @param array<string, mixed> $body
     * @return array{errors: array<string, string>, values: array{label: string, url: string, page_id: ?int, sort_order: int, target: string, css_class: string}}
     */
    public function validate(array $body): array
    {
        $errors = [];

        $label = $this->str($body, 'label');
        if ($label === '') {
            $errors['label'] = 'Label is required.';
        } elseif (mb_strlen($label) > 191) {
            $errors['label'] = 'Label is too long.';
        }

        $url = $this->str($body, 'url');
        if (mb_strlen($url) > 2000) {
            $errors['url'] = 'URL is too long.';
        }

        $pageIdRaw = $body['page_id'] ?? '';
        $pageId = null;
        if ($pageIdRaw !== '' && $pageIdRaw !== null && $pageIdRaw !== '0') {
            $pageId = (int) $pageIdRaw;
            if ($pageId < 1) {
                $errors['page_id'] = 'Pick a valid page or leave internal page empty.';
                $pageId = null;
            }
        }

        if ($pageId === null && $url === '') {
            $errors['url'] = 'Enter a URL or choose an internal page.';
        }

        $sortOrder = (int) ($body['sort_order'] ?? 0);
        if ($sortOrder < 0) {
            $errors['sort_order'] = 'Sort order cannot be negative.';
        }

        $target = $this->str($body, 'target');
        if ($target === '') {
            $target = '_self';
        }
        if (!in_array($target, ['_self', '_blank'], true)) {
            $errors['target'] = 'Target must be same window or new tab.';
        }

        $cssClass = $this->str($body, 'css_class');
        if ($cssClass !== '' && !preg_match('/^[a-zA-Z0-9_\- ]+$/', $cssClass)) {
            $errors['css_class'] = 'CSS class may only contain letters, numbers, spaces, hyphens, and underscores.';
        }
        if (mb_strlen($cssClass) > 191) {
            $errors['css_class'] = 'CSS class is too long.';
        }

        return [
            'errors' => $errors,
            'values' => [
                'label' => $label,
                'url' => $url,
                'page_id' => $pageId,
                'sort_order' => $sortOrder,
                'target' => $target,
                'css_class' => $cssClass,
            ],
        ];
    }

    private function str(array $body, string $key): string
    {
        $v = $body[$key] ?? '';

        return trim(is_string($v) ? str_replace("\0", '', $v) : '');
    }
}
