<?php

declare(strict_types=1);

namespace App\Ai;

use PDO;

final class AiBlogChatService
{
    public const EDITOR_ASSISTANT_PROMPT = <<<'SYS'
You are an editorial assistant inside a content management system. Help the user plan and refine articles or entries: audience, angle, outline, tone, and SEO ideas. Be concise (roughly under 150 words unless they ask for more detail). Do not output full HTML article bodies.

Important: this chat does not create or save a post. The user must use the **Create draft entry** button below the chat (after choosing a content type) to turn the conversation into a real draft in the editor.

When the plan feels ready, or the user says they are done (e.g. "no", "that's enough", "go ahead", "looks good"), reply briefly and clearly tell them to pick the content type and click **Create draft entry**. If one essential detail is still missing, ask it first, then remind them about **Create draft entry** after they answer.
SYS;

    public function __construct(
        private readonly OpenAiChatClient $client = new OpenAiChatClient()
    ) {
    }

    /**
     * @return list<array{role: string, content: string}>
     */
    public static function openAiMessagesFromSession(): array
    {
        $messages = [['role' => 'system', 'content' => self::EDITOR_ASSISTANT_PROMPT]];
        foreach (AiBlogChatSession::messages() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $role = (string) ($row['role'] ?? '');
            $content = (string) ($row['content'] ?? '');
            if ($role !== 'user' && $role !== 'assistant') {
                continue;
            }
            if (trim($content) === '') {
                continue;
            }
            $messages[] = ['role' => $role, 'content' => $content];
        }

        return $messages;
    }

    public function appendUserMessageAndReply(
        string $apiKey,
        string $model,
        string $userText,
        ?PDO $pdo = null,
        ?int $userId = null
    ): string {
        $userText = trim(str_replace("\0", '', $userText));
        if ($userText === '') {
            throw new \InvalidArgumentException('Message is required.');
        }
        if (mb_strlen($userText) > 8000) {
            throw new \InvalidArgumentException('Message is too long.');
        }

        AiBlogChatSession::appendWithPersist($pdo, $userId, 'user', $userText);

        $messages = self::openAiMessagesFromSession();
        try {
            $reply = $this->client->chatCompletionText($apiKey, $model, $messages, 0.7, 1200);
        } catch (\Throwable $e) {
            AiBlogChatSession::popLastIfRoleWithPersist($pdo, $userId, 'user');
            throw $e;
        }
        AiBlogChatSession::appendWithPersist($pdo, $userId, 'assistant', $reply);

        return $reply;
    }
}
