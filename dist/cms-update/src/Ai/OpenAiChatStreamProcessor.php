<?php

declare(strict_types=1);

namespace App\Ai;

/**
 * Drives OpenAI chat-completions streaming (SSE) and re-emits client-facing SSE events.
 *
 * @phpstan-import-type ChatMessage from AiBlogChatSession as ChatMessage
 */
final class OpenAiChatStreamProcessor
{
    /** @var \CurlMultiHandle|null */
    private $mh = null;

    /** @var \CurlHandle|null */
    private $ch = null;

    private string $openAiLineBuffer = '';

    private string $outBuffer = '';

    private string $assistantAccum = '';

    private bool $receivedDoneToken = false;

    private bool $curlFinished = false;

    private bool $successFinalized = false;

    private bool $closed = false;

    private bool $rolledBackUser = false;

    private bool $assistantCommitted = false;

    /**
     * @param list<array{role: string, content: string}> $messages
     * @param callable(string): list<ChatMessage>       $onComplete
     * @param (callable(): void)|null                   $onStreamError rollback user row on failure before assistant
     */
    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
        private readonly array $messages,
        private $onComplete,
        private readonly mixed $onStreamError = null,
    ) {
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        if ($this->ch !== null && $this->mh !== null) {
            curl_multi_remove_handle($this->mh, $this->ch);
        }
        $this->ch = null;
        if ($this->mh !== null) {
            curl_multi_close($this->mh);
        }
        $this->mh = null;
    }

    public function readUpTo(int $length): string
    {
        if ($this->closed) {
            return '';
        }
        $length = max(1, $length);
        while ($this->outBuffer === '' && !$this->isFinished()) {
            $this->pump();
        }
        if ($this->outBuffer === '') {
            return '';
        }
        $take = min($length, strlen($this->outBuffer));
        $out = substr($this->outBuffer, 0, $take);
        $this->outBuffer = substr($this->outBuffer, $take);

        return $out;
    }

    public function isFinished(): bool
    {
        return $this->curlFinished && $this->outBuffer === '' && $this->successFinalized;
    }

    private function initCurl(): void
    {
        $this->mh = curl_multi_init();
        $this->ch = curl_init('https://api.openai.com/v1/chat/completions');
        if ($this->ch === false) {
            $this->curlFinished = true;
            $this->emitError('Could not initialize HTTP request.');
            curl_multi_close($this->mh);
            $this->mh = null;

            return;
        }
        $payload = json_encode([
            'model' => $this->model,
            'messages' => $this->messages,
            'stream' => true,
            'temperature' => 0.7,
            'max_tokens' => 1200,
        ], JSON_THROW_ON_ERROR);

        curl_setopt_array($this->ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_WRITEFUNCTION => function ($ch, string $chunk): int {
                $this->feedOpenAiChunk($chunk);

                return strlen($chunk);
            },
        ]);
        curl_multi_add_handle($this->mh, $this->ch);
    }

    private function pump(): void
    {
        if ($this->successFinalized && $this->curlFinished) {
            return;
        }
        if ($this->ch === null) {
            $this->initCurl();
        }
        if ($this->mh === null || $this->ch === null) {
            return;
        }

        do {
            $status = curl_multi_exec($this->mh, $running);
        } while ($status === CURLM_CALL_MULTI_PERFORM);

        while ($info = curl_multi_info_read($this->mh)) {
            if ($info['msg'] === CURLMSG_DONE) {
                $this->onCurlDone();
            }
        }

        if ($running && !$this->curlFinished) {
            curl_multi_select($this->mh, 0.08);
        }
    }

    private function feedOpenAiChunk(string $chunk): void
    {
        $this->openAiLineBuffer .= $chunk;
        while (($pos = strpos($this->openAiLineBuffer, "\n")) !== false) {
            $line = substr($this->openAiLineBuffer, 0, $pos);
            $this->openAiLineBuffer = substr($this->openAiLineBuffer, $pos + 1);
            $this->processOpenAiLine(rtrim($line, "\r"));
        }
    }

    private function processOpenAiLine(string $line): void
    {
        if ($line === '' || str_starts_with($line, ':')) {
            return;
        }
        if (!str_starts_with($line, 'data: ')) {
            return;
        }
        $data = trim(substr($line, 6));
        if ($data === '') {
            return;
        }
        if ($data === '[DONE]') {
            $this->receivedDoneToken = true;
            $this->finalizeSuccess();

            return;
        }
        try {
            /** @var mixed $j */
            $j = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return;
        }
        if (!is_array($j)) {
            return;
        }
        if (isset($j['error'])) {
            $err = $j['error'];
            $msg = is_array($err) ? (string) ($err['message'] ?? 'API error') : (string) $err;
            $this->emitError($msg);

            return;
        }
        $choices = $j['choices'] ?? null;
        if (!is_array($choices) || $choices === []) {
            return;
        }
        $c0 = $choices[0];
        if (!is_array($c0)) {
            return;
        }
        $delta = $c0['delta'] ?? null;
        if (!is_array($delta)) {
            return;
        }
        $content = $delta['content'] ?? null;
        if (!is_string($content) || $content === '') {
            return;
        }
        $this->assistantAccum .= $content;
        $this->emitDelta($content);
    }

    private function emitDelta(string $d): void
    {
        try {
            $this->outBuffer .= 'data: ' . json_encode(['type' => 'delta', 'd' => $d], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n\n";
        } catch (\JsonException) {
            // ignore rare encoding failure for a single delta
        }
    }

    private function emitError(string $m, bool $rollbackUser = true): void
    {
        if ($this->successFinalized) {
            return;
        }
        if (
            $rollbackUser
            && !$this->assistantCommitted
            && $this->onStreamError !== null
            && !$this->rolledBackUser
        ) {
            $this->rolledBackUser = true;
            ($this->onStreamError)();
        }
        $this->successFinalized = true;
        $this->curlFinished = true;
        try {
            $this->outBuffer .= 'data: ' . json_encode(['type' => 'error', 'message' => $m], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n\n";
        } catch (\JsonException) {
            $this->outBuffer .= 'data: {"type":"error","message":"encoding_error"}\n\n';
        }
    }

    private function finalizeSuccess(): void
    {
        if ($this->successFinalized) {
            return;
        }
        if (trim($this->assistantAccum) === '') {
            $this->emitError('The model returned an empty reply.');

            return;
        }
        try {
            /** @var list<ChatMessage> $messages */
            $messages = ($this->onComplete)($this->assistantAccum);
            $this->assistantCommitted = true;
            $this->outBuffer .= 'data: ' . json_encode(['type' => 'done', 'messages' => $messages], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n\n";
            $this->successFinalized = true;
        } catch (\Throwable $e) {
            $this->emitError($e->getMessage());
        }
    }

    private function onCurlDone(): void
    {
        if ($this->curlFinished) {
            return;
        }
        $this->curlFinished = true;
        if ($this->successFinalized) {
            return;
        }
        if ($this->ch === null) {
            return;
        }
        $code = (int) curl_getinfo($this->ch, CURLINFO_HTTP_CODE);
        if ($code >= 400) {
            $raw = trim($this->openAiLineBuffer);
            if ($raw !== '') {
                try {
                    /** @var mixed $j */
                    $j = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                    if (is_array($j) && isset($j['error']) && is_array($j['error']) && isset($j['error']['message'])) {
                        $this->emitError((string) $j['error']['message']);

                        return;
                    }
                } catch (\JsonException) {
                }
            }
            $this->emitError('OpenAI request failed (HTTP ' . $code . ').');

            return;
        }
        if (!$this->receivedDoneToken) {
            if (trim($this->assistantAccum) === '') {
                $this->emitError('Stream ended without a reply.');
            } else {
                $this->finalizeSuccess();
            }
        }
    }
}
