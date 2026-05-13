<?php

declare(strict_types=1);

namespace App\Ai;

/**
 * Minimal OpenAI Chat Completions client (JSON mode).
 */
final class OpenAiChatClient
{
    public function __construct(
        private readonly float $timeoutSeconds = 120.0
    ) {
    }

    /**
     * @param list<array{role: string, content: string}> $messages
     */
    public function chatJsonObject(
        string $apiKey,
        string $model,
        array $messages,
        float $temperature = 0.65
    ): string {
        $payload = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => $temperature,
            'response_format' => ['type' => 'json_object'],
        ];
        $json = json_encode($payload, JSON_THROW_ON_ERROR);

        $ch = curl_init('https://api.openai.com/v1/chat/completions');
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
        if ($raw === false || $errno !== 0) {
            throw new OpenAiException('OpenAI request failed: ' . ($err !== '' ? $err : 'unknown error'));
        }

        /** @var mixed $decoded */
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new OpenAiException('OpenAI returned invalid JSON (HTTP ' . $code . ').');
        }

        if ($code >= 400) {
            $msg = is_string($decoded['error']['message'] ?? null)
                ? (string) $decoded['error']['message']
                : 'HTTP ' . $code;
            throw new OpenAiException($msg);
        }

        $choices = $decoded['choices'] ?? null;
        if (!is_array($choices) || $choices === []) {
            throw new OpenAiException('OpenAI response had no choices.');
        }

        $first = $choices[0];
        if (!is_array($first)) {
            throw new OpenAiException('OpenAI response shape was unexpected.');
        }

        $message = $first['message'] ?? null;
        if (!is_array($message)) {
            throw new OpenAiException('OpenAI response missing message.');
        }

        $content = $message['content'] ?? '';
        if (!is_string($content) || trim($content) === '') {
            throw new OpenAiException('OpenAI returned empty content.');
        }

        return $content;
    }

    /**
     * Plain text assistant reply (no JSON mode).
     *
     * @param list<array{role: string, content: string}> $messages
     */
    public function chatCompletionText(
        string $apiKey,
        string $model,
        array $messages,
        float $temperature = 0.7,
        int $maxTokens = 1200
    ): string {
        $payload = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => $temperature,
            'max_tokens' => max(256, min(4096, $maxTokens)),
        ];
        $json = json_encode($payload, JSON_THROW_ON_ERROR);

        $ch = curl_init('https://api.openai.com/v1/chat/completions');
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
        if ($raw === false || $errno !== 0) {
            throw new OpenAiException('OpenAI request failed: ' . ($err !== '' ? $err : 'unknown error'));
        }

        /** @var mixed $decoded */
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new OpenAiException('OpenAI returned invalid JSON (HTTP ' . $code . ').');
        }

        if ($code >= 400) {
            $msg = is_string($decoded['error']['message'] ?? null)
                ? (string) $decoded['error']['message']
                : 'HTTP ' . $code;
            throw new OpenAiException($msg);
        }

        $choices = $decoded['choices'] ?? null;
        if (!is_array($choices) || $choices === []) {
            throw new OpenAiException('OpenAI response had no choices.');
        }

        $first = $choices[0];
        if (!is_array($first)) {
            throw new OpenAiException('OpenAI response shape was unexpected.');
        }

        $message = $first['message'] ?? null;
        if (!is_array($message)) {
            throw new OpenAiException('OpenAI response missing message.');
        }

        $content = $message['content'] ?? '';
        if (!is_string($content)) {
            throw new OpenAiException('OpenAI returned invalid content.');
        }
        $content = trim($content);
        if ($content === '') {
            throw new OpenAiException('OpenAI returned empty content.');
        }

        return $content;
    }
}
