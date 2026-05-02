<?php

declare(strict_types=1);

namespace App\Api;

/**
 * Request attribute set by PublicApiKeyMiddleware after successful API authentication.
 */
final class PublicApiAuthContext
{
    public const ATTR = 'public_api_auth';

    /** @param list<string> $scopes read, read_drafts, write */
    public function __construct(
        public readonly string $source,
        public readonly ?int $apiKeyId,
        public readonly array $scopes,
    ) {
    }

    public function can(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }
}
