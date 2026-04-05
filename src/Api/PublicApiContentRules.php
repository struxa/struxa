<?php

declare(strict_types=1);

namespace App\Api;

use App\Content\ContentType;

final class PublicApiContentRules
{
    public static function typeAllowedForRead(ContentType $t, PublicApiAuthContext $auth): bool
    {
        return $t->hasPublicRoute || $auth->can('read_drafts');
    }

    /**
     * @return array{ok: true, statuses: list<string>}|array{ok: false, error: string, code: int}
     */
    public static function statusesForEntryList(string $status, PublicApiAuthContext $auth): array
    {
        $s = strtolower(trim($status));
        if ($s === '' || $s === 'published') {
            return ['ok' => true, 'statuses' => ['published']];
        }
        if (!$auth->can('read_drafts')) {
            return ['ok' => false, 'error' => 'insufficient_scope', 'code' => 403];
        }
        if ($s === 'all') {
            return ['ok' => true, 'statuses' => ['draft', 'in_review', 'approved', 'published', 'archived']];
        }
        $allowed = ['draft', 'in_review', 'approved', 'published', 'archived'];
        if (in_array($s, $allowed, true)) {
            return ['ok' => true, 'statuses' => [$s]];
        }

        return ['ok' => false, 'error' => 'invalid_status', 'code' => 400];
    }
}
