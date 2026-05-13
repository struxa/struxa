<?php

declare(strict_types=1);

namespace AviosDestinationReviewPlugin;

use App\Ai\OpenAiException;
use AviosDestinationReviewPlugin\OpenAiImageClient;
use App\Media\MediaRepository;
use App\Media\MediaStorage;
use App\Media\MediaUploadService;
use PDO;

/**
 * Generates a hero image for a destination via the OpenAI Images API and registers it
 * with the CMS media library. Returns the new cms_media row id on success so callers
 * can attach it as a featured image.
 *
 * The output PNG is written to `public/uploads/YYYY/MM/<random>.png` to match the same
 * convention as a regular admin upload (so the media library treats it identically).
 */
final class ImageGenerator
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly SettingsRepository $settings,
    ) {
    }

    /**
     * Build the user-facing prompt by substituting {{destination}} / {{iata}}.
     */
    public function renderPrompt(string $destination, string $iata): string
    {
        $template = $this->settings->get()['image_prompt_template'];

        return strtr($template, [
            '{{destination}}' => $destination,
            '{{iata}}' => strtoupper($iata),
        ]);
    }

    /**
     * Generate one image and store it in the media library.
     *
     * @return array{ok:true, media_id:int, path:string, prompt:string, model:string, size:string}
     *        |array{ok:false, error:string}
     */
    public function generateAndStore(string $apiKey, string $destination, string $iata): array
    {
        $cfg = $this->settings->get();
        $prompt = $this->renderPrompt($destination, $iata);
        $model = $cfg['image_model'];
        $size = $cfg['image_size'];

        try {
            $img = (new OpenAiImageClient(180.0))->generate($apiKey, $prompt, $model, $size);
        } catch (OpenAiException $e) {
            return ['ok' => false, 'error' => 'OpenAI image error: ' . $e->getMessage()];
        }

        $bytes = $img['bytes'];
        $mime = $img['mime'];
        $ext = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            default => 'png',
        };

        $projectRoot = MediaUploadService::projectRoot();
        $subdir = date('Y/m');
        $dir = $projectRoot . '/public/uploads/' . $subdir;
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return ['ok' => false, 'error' => 'Could not create uploads directory.'];
        }
        if (!MediaStorage::isDirectoryUnderUploads($projectRoot, $dir)) {
            return ['ok' => false, 'error' => 'Refusing to write outside public/uploads.'];
        }

        $filename = 'adr-' . strtolower($iata) . '-' . bin2hex(random_bytes(8)) . '.' . $ext;
        $webPath = MediaStorage::WEB_PREFIX . $subdir . '/' . $filename;
        if (!MediaStorage::isSafeManagedWebPath($webPath)) {
            return ['ok' => false, 'error' => 'Computed an unsafe upload path.'];
        }

        $absolute = $projectRoot . '/public' . $webPath;
        if (@file_put_contents($absolute, $bytes) === false) {
            return ['ok' => false, 'error' => 'Could not write generated image to disk.'];
        }

        // Defence-in-depth: validate the saved file with the same verifier the upload
        // service uses, so we never register a non-image into cms_media.
        $maxBytes = MediaUploadService::maxBytesFromEnv();
        $check = MediaStorage::verifyRasterImageAtPath($absolute, $ext, max($maxBytes, strlen($bytes) + 1024));
        if ($check['ok'] !== true) {
            @unlink($absolute);

            return ['ok' => false, 'error' => 'Generated image failed validation: ' . $check['error']];
        }

        $size = filesize($absolute);
        if ($size === false) {
            @unlink($absolute);

            return ['ok' => false, 'error' => 'Could not stat saved image.'];
        }

        $repo = new MediaRepository($this->pdo);
        try {
            $mediaId = $repo->insert(
                filename: $filename,
                originalName: $destination . ' (' . strtoupper($iata) . ').' . $ext,
                mimeType: $check['mime'],
                extension: $ext,
                fileSize: (int) $size,
                path: $webPath,
                width: $check['width'],
                height: $check['height'],
                uploadedBy: null
            );
        } catch (\Throwable $e) {
            @unlink($absolute);

            return ['ok' => false, 'error' => 'Could not register media: ' . $e->getMessage()];
        }

        // Friendly alt text + title so the media library and Twig img output are SEO-clean.
        $alt = 'Avios flights to ' . $destination . ' (' . strtoupper($iata) . ') — editorial photograph';
        $title = $destination . ' (' . strtoupper($iata) . ')';
        $repo->updateMetadata($mediaId, $alt, $title, null);

        return [
            'ok' => true,
            'media_id' => $mediaId,
            'path' => $webPath,
            'prompt' => $prompt,
            'model' => $img['model'],
            'size' => $img['size'],
        ];
    }
}
