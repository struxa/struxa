<?php

declare(strict_types=1);

namespace App\Media;

final class MediaMetadataValidator
{
    private const MAX_ALT = 255;
    private const MAX_TITLE = 255;
    private const MAX_CAPTION = 5000;

    /**
     * @param array<string, mixed> $body
     * @return array{errors: array<string, string>, values: array{alt_text: ?string, title: ?string, caption: ?string}}
     */
    public function validate(array $body): array
    {
        $errors = [];

        $alt = $this->nullableStr($body, 'alt_text');
        if ($alt !== null && mb_strlen($alt) > self::MAX_ALT) {
            $errors['alt_text'] = 'Alt text is too long.';
        }

        $title = $this->nullableStr($body, 'title');
        if ($title !== null && mb_strlen($title) > self::MAX_TITLE) {
            $errors['title'] = 'Title is too long.';
        }

        $caption = $this->nullableStr($body, 'caption');
        if ($caption !== null && mb_strlen($caption) > self::MAX_CAPTION) {
            $errors['caption'] = 'Caption is too long.';
        }

        return [
            'errors' => $errors,
            'values' => [
                'alt_text' => $alt,
                'title' => $title,
                'caption' => $caption,
            ],
        ];
    }

    private function nullableStr(array $body, string $key): ?string
    {
        $v = $body[$key] ?? '';
        if (!is_string($v)) {
            return null;
        }
        $v = trim(str_replace("\0", '', $v));

        return $v === '' ? null : $v;
    }
}
