<?php

declare(strict_types=1);

namespace App\Media;

use Psr\Http\Message\UploadedFileInterface;

final class MediaUploadService
{
    public function __construct(
        private readonly MediaRepository $repository,
        private readonly string $projectRoot,
        private readonly int $maxBytes
    ) {
    }

    /**
     * @return array{ok: true, id: int}|array{ok: false, error: string}
     */
    public function handleUpload(UploadedFileInterface $file, ?int $cmsUserId): array
    {
        if ($file->getError() !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => $this->uploadErrorMessage($file->getError())];
        }

        if ($file->getSize() !== null && $file->getSize() > $this->maxBytes) {
            return ['ok' => false, 'error' => 'File is too large.'];
        }

        $clientName = $file->getClientFilename() ?? 'upload';
        $original = $this->sanitizeOriginalName($clientName);
        $ext = strtolower(pathinfo($clientName, PATHINFO_EXTENSION));
        if ($ext === '' || !isset(MediaStorage::EXTENSION_TO_MIMES[$ext])) {
            return ['ok' => false, 'error' => 'Only JPG, PNG, WebP, and GIF images are allowed.'];
        }

        $tmp = $file->getStream()->getMetadata('uri');
        $destroyTmp = false;
        if (!is_string($tmp) || $tmp === '' || !is_readable($tmp)) {
            $file->getStream()->rewind();
            $buf = $file->getStream()->getContents();
            if ($buf === '') {
                return ['ok' => false, 'error' => 'Could not read the uploaded file.'];
            }
            if (strlen($buf) > $this->maxBytes) {
                return ['ok' => false, 'error' => 'File is too large.'];
            }
            $tmp = tempnam(sys_get_temp_dir(), 'cmsu');
            if ($tmp === false) {
                return ['ok' => false, 'error' => 'Could not stage the upload.'];
            }
            file_put_contents($tmp, $buf);
            $destroyTmp = true;
        }

        $first = MediaStorage::verifyRasterImageAtPath($tmp, $ext, $this->maxBytes);
        if ($first['ok'] !== true) {
            if ($destroyTmp) {
                @unlink($tmp);
            }

            return ['ok' => false, 'error' => $first['error']];
        }

        $unique = bin2hex(random_bytes(16)) . '.' . $ext;
        $subdir = date('Y/m');
        $webPath = MediaStorage::WEB_PREFIX . $subdir . '/' . $unique;
        if (!MediaStorage::isSafeManagedWebPath($webPath)) {
            if ($destroyTmp) {
                @unlink($tmp);
            }

            return ['ok' => false, 'error' => 'Invalid storage path.'];
        }

        $dir = $this->projectRoot . '/public/uploads/' . $subdir;
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            if ($destroyTmp) {
                @unlink($tmp);
            }

            return ['ok' => false, 'error' => 'Could not create upload directory.'];
        }

        if (!MediaStorage::isDirectoryUnderUploads($this->projectRoot, $dir)) {
            if ($destroyTmp) {
                @unlink($tmp);
            }

            return ['ok' => false, 'error' => 'Invalid upload directory.'];
        }

        $absolute = $this->projectRoot . '/public' . $webPath;
        try {
            if ($destroyTmp) {
                if (!rename($tmp, $absolute)) {
                    if (!copy($tmp, $absolute)) {
                        @unlink($tmp);

                        return ['ok' => false, 'error' => 'Could not save the file.'];
                    }
                    @unlink($tmp);
                }
            } else {
                $file->moveTo($absolute);
            }
        } catch (\RuntimeException) {
            if ($destroyTmp) {
                @unlink($tmp);
            }

            return ['ok' => false, 'error' => 'Could not save the file.'];
        }

        $destroyTmp = false;

        $second = MediaStorage::verifyRasterImageAtPath($absolute, $ext, $this->maxBytes);
        if ($second['ok'] !== true) {
            @unlink($absolute);

            return ['ok' => false, 'error' => $second['error']];
        }

        $size = filesize($absolute);
        if ($size === false) {
            @unlink($absolute);

            return ['ok' => false, 'error' => 'Could not verify the saved file.'];
        }

        try {
            $id = $this->repository->insert(
                $unique,
                $original,
                $second['mime'],
                $ext,
                (int) $size,
                $webPath,
                $second['width'],
                $second['height'],
                $cmsUserId
            );
        } catch (\Throwable) {
            @unlink($absolute);

            return ['ok' => false, 'error' => 'Could not save media metadata.'];
        }

        return ['ok' => true, 'id' => $id];
    }

    public static function maxBytesFromEnv(): int
    {
        $mb = (int) ($_ENV['CMS_UPLOAD_MAX_MB'] ?? 5);
        if ($mb < 1) {
            $mb = 5;
        }
        if ($mb > 50) {
            $mb = 50;
        }

        return $mb * 1024 * 1024;
    }

    public static function projectRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    private function sanitizeOriginalName(string $name): string
    {
        $name = str_replace(["\0", "\r", "\n"], '', basename($name));
        if (mb_strlen($name) > 255) {
            $name = mb_substr($name, 0, 255);
        }

        return $name !== '' ? $name : 'upload';
    }

    private function uploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File exceeds upload size limits.',
            UPLOAD_ERR_PARTIAL => 'The file was only partially uploaded.',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
            default => 'Upload failed.',
        };
    }
}
