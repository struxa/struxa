<?php

declare(strict_types=1);

namespace AviosDestinationReviewPlugin;

use App\Ai\OpenAiException;

/**
 * Minimal OpenAI Images client (POST /v1/images/generations).
 *
 * Originally lived at src/Ai/OpenAiImageClient.php; moved into this plugin so
 * Struxa CMS self-updates can't clobber it. Re-uses core's App\Ai\OpenAiException
 * for error reporting (stable, ships with the CMS).
 *
 * Returns the raw image bytes for the first generated image. Defaults target
 * `gpt-image-1` which always responds with `b64_json` regardless of `response_format`,
 * but the code also handles a `url` response so callers can swap in `dall-e-3` later.
 */
final class OpenAiImageClient
{
    public const DEFAULT_MODEL = 'gpt-image-1';

    /** Sizes accepted by gpt-image-1 / dall-e-3. Used to defensively clamp user input. */
    public const ALLOWED_SIZES = [
        '1024x1024',
        '1024x1536',
        '1536x1024',
        '1792x1024',
        '1024x1792',
        'auto',
    ];

    public function __construct(
        private readonly float $timeoutSeconds = 120.0
    ) {
    }

    /**
     * Generate one image and return its decoded binary bytes + mime type.
     *
     * @return array{bytes:string, mime:string, model:string, size:string}
     * @throws OpenAiException on transport, parse, or HTTP errors.
     */
    public function generate(
        string $apiKey,
        string $prompt,
        string $model = self::DEFAULT_MODEL,
        string $size = '1536x1024'
    ): array {
        $size = in_array($size, self::ALLOWED_SIZES, true) ? $size : '1536x1024';

        $payload = [
            'model' => $model,
            'prompt' => $prompt,
            'size' => $size,
            'n' => 1,
        ];
        $json = json_encode($payload, JSON_THROW_ON_ERROR);

        $ch = curl_init('https://api.openai.com/v1/images/generations');
        if ($ch === false) {
            throw new OpenAiException('Could not initialize HTTP client.');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => (int) ceil($this->timeoutSeconds),
        ]);

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = $errno !== 0 ? curl_error($ch) : '';
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $errno !== 0) {
            throw new OpenAiException('OpenAI image request failed: ' . ($err !== '' ? $err : 'unknown error'));
        }

        /** @var mixed $decoded */
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            throw new OpenAiException('OpenAI image API returned invalid JSON (HTTP ' . $code . ').');
        }

        if ($code >= 400) {
            $msg = is_string($decoded['error']['message'] ?? null)
                ? (string) $decoded['error']['message']
                : 'HTTP ' . $code;
            throw new OpenAiException($msg);
        }

        $data = $decoded['data'] ?? null;
        if (!is_array($data) || $data === []) {
            throw new OpenAiException('OpenAI image response had no data.');
        }
        $first = $data[0];
        if (!is_array($first)) {
            throw new OpenAiException('OpenAI image response shape was unexpected.');
        }

        $bytes = null;
        if (isset($first['b64_json']) && is_string($first['b64_json']) && $first['b64_json'] !== '') {
            $bytes = base64_decode($first['b64_json'], true);
            if ($bytes === false) {
                throw new OpenAiException('OpenAI returned an unreadable base64 image payload.');
            }
        } elseif (isset($first['url']) && is_string($first['url']) && $first['url'] !== '') {
            $bytes = $this->downloadUrl($first['url']);
        }

        if (!is_string($bytes) || $bytes === '') {
            throw new OpenAiException('OpenAI image response did not include image data.');
        }

        // gpt-image-1 / dall-e-3 deliver PNG. Sniff defensively in case the API changes.
        $mime = $this->detectMime($bytes);

        return [
            'bytes' => $bytes,
            'mime' => $mime,
            'model' => $model,
            'size' => $size,
        ];
    }

    private function downloadUrl(string $url): string
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new OpenAiException('Could not initialise URL download.');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => (int) ceil($this->timeoutSeconds),
        ]);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $errno !== 0 || $code >= 400) {
            throw new OpenAiException('Could not download generated image (HTTP ' . $code . ').');
        }

        return (string) $body;
    }

    private function detectMime(string $bytes): string
    {
        if (str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            return 'image/png';
        }
        if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }
        if (str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP') {
            return 'image/webp';
        }

        return 'image/png';
    }
}
