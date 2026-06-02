<?php

declare(strict_types=1);

namespace App\Dist;

use Psr\Http\Message\UploadedFileInterface;

/**
 * Reads an uploaded theme/plugin ZIP from multipart admin forms.
 */
final class PackageZipUploadReader
{
    public const FIELD_NAME = 'package_zip';

    /**
     * @param array<string, mixed> $uploadedFiles
     *
     * @return array{ok: true, body: string, filename: string}|array{ok: false, error: string}
     */
    public static function read(array $uploadedFiles, int $maxBytes, string $field = self::FIELD_NAME): array
    {
        $file = $uploadedFiles[$field] ?? null;
        if (!$file instanceof UploadedFileInterface) {
            return ['ok' => false, 'error' => 'Choose a ZIP file to upload.'];
        }

        $err = (int) $file->getError();
        if ($err !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => self::uploadErrorMessage($err)];
        }

        $size = $file->getSize();
        if ($size !== null && $size > $maxBytes) {
            return [
                'ok' => false,
                'error' => 'ZIP file is too large (max ' . (int) round($maxBytes / 1_000_000) . ' MB).',
            ];
        }

        $filename = (string) ($file->getClientFilename() ?? '');
        $lower = strtolower($filename);
        if ($lower !== '' && !str_ends_with($lower, '.zip')) {
            return ['ok' => false, 'error' => 'Upload must be a .zip file.'];
        }

        $body = (string) $file->getStream();
        if ($body === '') {
            return ['ok' => false, 'error' => 'Uploaded file is empty.'];
        }
        if (strlen($body) > $maxBytes) {
            return ['ok' => false, 'error' => 'ZIP file is too large.'];
        }

        return ['ok' => true, 'body' => $body, 'filename' => $filename !== '' ? $filename : 'package.zip'];
    }

    private static function uploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Uploaded file exceeds the server size limit.',
            UPLOAD_ERR_PARTIAL => 'Upload was interrupted. Try again.',
            UPLOAD_ERR_NO_FILE => 'Choose a ZIP file to upload.',
            default => 'Could not read the uploaded file.',
        };
    }
}
