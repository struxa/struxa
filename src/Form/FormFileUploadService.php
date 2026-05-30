<?php

declare(strict_types=1);

namespace App\Form;

use Psr\Http\Message\UploadedFileInterface;

final class FormFileUploadService
{
    /** @var array<string, list<string>> */
    private const DEFAULT_EXTENSIONS = [
        'pdf' => ['application/pdf'],
        'doc' => ['application/msword'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'txt' => ['text/plain'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],
        'zip' => ['application/zip', 'application/x-zip-compressed'],
    ];

    public function __construct(
        private readonly string $projectRoot,
        private readonly int $defaultMaxBytes = 5_242_880,
    ) {
    }

    /**
     * @param array<string, mixed> $fieldSettings
     *
     * @return array{ok: true, path: string, original: string}|array{ok: false, error: string}
     */
    public function store(UploadedFileInterface $file, int $formId, array $fieldSettings = []): array
    {
        if ($file->getError() !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => $this->uploadErrorMessage($file->getError())];
        }

        $maxBytes = (int) ($fieldSettings['max_mb'] ?? 5) * 1024 * 1024;
        if ($maxBytes < 1) {
            $maxBytes = $this->defaultMaxBytes;
        }
        if ($file->getSize() !== null && $file->getSize() > $maxBytes) {
            return ['ok' => false, 'error' => 'File is too large.'];
        }

        $clientName = $file->getClientFilename() ?? 'upload';
        $ext = strtolower(pathinfo($clientName, PATHINFO_EXTENSION));
        $allowed = $this->allowedExtensions($fieldSettings);
        if ($ext === '' || !in_array($ext, $allowed, true)) {
            return ['ok' => false, 'error' => 'File type not allowed.'];
        }

        $dir = $this->projectRoot . '/public/uploads/forms/' . $formId;
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return ['ok' => false, 'error' => 'Could not create upload directory.'];
        }

        $safeBase = preg_replace('/[^a-z0-9._-]+/i', '-', pathinfo($clientName, PATHINFO_FILENAME)) ?? 'file';
        $safeBase = trim($safeBase, '-');
        if ($safeBase === '') {
            $safeBase = 'file';
        }
        $filename = $safeBase . '-' . bin2hex(random_bytes(8)) . '.' . $ext;
        $dest = $dir . '/' . $filename;

        $file->moveTo($dest);
        if (!is_file($dest)) {
            return ['ok' => false, 'error' => 'Upload failed.'];
        }

        $relative = '/uploads/forms/' . $formId . '/' . $filename;

        return ['ok' => true, 'path' => $relative, 'original' => $clientName];
    }

    /**
     * @param array<string, mixed> $fieldSettings
     *
     * @return list<string>
     */
    public function allowedExtensions(array $fieldSettings = []): array
    {
        $custom = $fieldSettings['extensions'] ?? null;
        if (is_array($custom) && $custom !== []) {
            return array_values(array_unique(array_map(static fn ($e): string => strtolower(trim((string) $e)), $custom)));
        }

        return array_keys(self::DEFAULT_EXTENSIONS);
    }

    private function uploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File is too large.',
            UPLOAD_ERR_PARTIAL => 'Upload was interrupted. Try again.',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
            default => 'Upload failed.',
        };
    }
}
