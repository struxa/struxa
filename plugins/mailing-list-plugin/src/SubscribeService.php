<?php

declare(strict_types=1);

namespace MailingListPlugin;

use App\Http\ClientIp;
use App\Security\FileRateLimiter;
use Psr\Http\Message\ServerRequestInterface as Request;

final class SubscribeService
{
    private const RATE_MAX = 12;
    private const RATE_WINDOW = 3600;

    public function __construct(
        private readonly ListRepository $lists,
        private readonly SubscriberRepository $subscribers,
        private readonly FileRateLimiter $rateLimiter,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     * @return array{ok: bool, status: int, message: string, code?: string}
     */
    public function handle(Request $request, array $body, bool $wantsJson): array
    {
        if (!empty($body['website_url'])) {
            return $this->respond($wantsJson, false, 400, 'Invalid submission.', 'spam');
        }

        $listSlug = trim(is_string($body['list'] ?? $body['list_slug'] ?? '') ? (string) ($body['list'] ?? $body['list_slug']) : '');
        if ($listSlug === '') {
            return $this->respond($wantsJson, false, 400, 'Choose a mailing list.', 'missing_list');
        }

        $list = $this->lists->findBySlug($listSlug);
        if ($list === null || !$list->isActive) {
            return $this->respond($wantsJson, false, 404, 'This mailing list is not available.', 'list_not_found');
        }

        $emailCheck = EmailValidator::normalizeAndValidate(is_string($body['email'] ?? '') ? (string) $body['email'] : '');
        if (!$emailCheck['ok']) {
            return $this->respond($wantsJson, false, 422, $emailCheck['error'], 'invalid_email');
        }
        $email = $emailCheck['email'];

        $ip = ClientIp::fromRequest($request);
        if (!$this->rateLimiter->hit('mailing_list_subscribe', (string) $list->id . ':' . $ip, self::RATE_MAX, self::RATE_WINDOW)) {
            return $this->respond($wantsJson, false, 429, 'Too many sign-up attempts. Try again later.', 'rate_limited');
        }

        $outcome = $this->subscribers->subscribe($list->id, $email);
        $message = match ($outcome) {
            'already' => 'You are already subscribed to this list.',
            'reactivated' => 'Welcome back — you are subscribed again.',
            default => 'Thanks — you are subscribed.',
        };

        return $this->respond($wantsJson, true, 200, $message, $outcome);
    }

    /**
     * @return array{ok: bool, status: int, message: string, code?: string}
     */
    private function respond(bool $wantsJson, bool $ok, int $status, string $message, string $code): array
    {
        return ['ok' => $ok, 'status' => $status, 'message' => $message, 'code' => $code];
    }
}
