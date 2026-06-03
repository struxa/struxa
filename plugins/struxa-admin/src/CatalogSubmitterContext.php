<?php

declare(strict_types=1);

namespace StruxaAdmin;

use App\CmsUserRepository;
use App\Http\ClientIp;
use PDO;
use Psr\Http\Message\ServerRequestInterface as Request;

final class CatalogSubmitterContext
{
    public function __construct(
        public readonly int $cmsUserId,
        public readonly string $username,
        public readonly string $email,
        public readonly string $ip,
        public readonly ?string $userAgent,
    ) {
    }

    /**
     * @param array<string, mixed> $viewData
     *
     * @return array{ok: true, ctx: self}|array{ok: false, errors: list<string>}
     */
    public static function fromRequest(PDO $pdo, Request $request, array $viewData): array
    {
        $phpauthId = isset($viewData['phpauth_user_id']) ? (int) $viewData['phpauth_user_id'] : 0;
        if ($phpauthId <= 0) {
            return ['ok' => false, 'errors' => ['You must be signed in to submit.']];
        }

        if (!CmsUserRepository::tableExists($pdo)) {
            return ['ok' => false, 'errors' => ['Member accounts are not available on this site yet.']];
        }

        $cmsUser = CmsUserRepository::findByPhpAuthId($pdo, $phpauthId);
        if ($cmsUser === null || (int) ($cmsUser['is_active'] ?? 0) !== 1) {
            return ['ok' => false, 'errors' => ['Your account is not active. Contact support if this is unexpected.']];
        }

        $username = trim((string) ($cmsUser['username'] ?? ''));
        if ($username === '') {
            $username = trim((string) ($viewData['user_username'] ?? ''));
        }
        if ($username === '') {
            $username = trim((string) ($viewData['user_display_name'] ?? ''));
        }
        if ($username === '') {
            $email = trim((string) ($cmsUser['email'] ?? ''));
            $at = strpos($email, '@');
            $username = $at !== false ? substr($email, 0, $at) : ($email !== '' ? $email : 'member');
        }

        $email = trim((string) ($cmsUser['email'] ?? ''));
        if ($email === '') {
            $email = trim((string) ($viewData['user_email'] ?? ''));
        }

        $ua = trim($request->getHeaderLine('User-Agent'));
        $ua = $ua !== '' ? mb_substr($ua, 0, 500) : null;

        return [
            'ok' => true,
            'ctx' => new self(
                (int) $cmsUser['id'],
                $username,
                $email,
                ClientIp::fromRequest($request),
                $ua,
            ),
        ];
    }
}
