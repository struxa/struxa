<?php

declare(strict_types=1);

namespace App\Media;

final class Media
{
    public function __construct(
        public readonly int $id,
        public readonly string $filename,
        public readonly string $originalName,
        public readonly string $mimeType,
        public readonly string $extension,
        public readonly int $fileSize,
        public readonly string $path,
        public readonly ?string $altText,
        public readonly ?string $title,
        public readonly ?string $caption,
        public readonly ?int $width,
        public readonly ?int $height,
        public readonly ?int $uploadedBy,
        public readonly ?int $folderId,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['filename'],
            (string) $row['original_name'],
            (string) $row['mime_type'],
            (string) $row['extension'],
            (int) $row['file_size'],
            (string) $row['path'],
            isset($row['alt_text']) && $row['alt_text'] !== '' ? (string) $row['alt_text'] : null,
            isset($row['title']) && $row['title'] !== '' ? (string) $row['title'] : null,
            isset($row['caption']) && $row['caption'] !== '' ? (string) $row['caption'] : null,
            isset($row['width']) && $row['width'] !== null && $row['width'] !== '' ? (int) $row['width'] : null,
            isset($row['height']) && $row['height'] !== null && $row['height'] !== '' ? (int) $row['height'] : null,
            isset($row['uploaded_by']) && $row['uploaded_by'] !== null && $row['uploaded_by'] !== '' ? (int) $row['uploaded_by'] : null,
            isset($row['folder_id']) && $row['folder_id'] !== null && $row['folder_id'] !== '' ? (int) $row['folder_id'] : null,
            (string) ($row['created_at'] ?? ''),
            (string) ($row['updated_at'] ?? ''),
        );
    }

    public function isImage(): bool
    {
        return str_starts_with(strtolower($this->mimeType), 'image/');
    }
}
