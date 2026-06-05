<?php

declare(strict_types=1);

namespace App\Analytics;

use App\Content\ReservedContentSlugs;
use App\Page\PageRepository;
use PDO;

final class ShortLinkService
{
    private const CODE_PATTERN = '/^[a-z0-9]{4,12}$/';

    public function __construct(
        private readonly ShortLinkRepository $links,
        private readonly PDO $pdo,
    ) {
    }

    /**
     * @return array{ok: true, id: int, code: string}|array{ok: false, error: string}
     */
    public function create(string $destinationUrl, ?string $requestedCode, ?string $label, ?int $createdBy): array
    {
        if (!ShortLinkConfig::enabled()) {
            return ['ok' => false, 'error' => 'Short links are disabled in settings.'];
        }

        $destinationUrl = trim($destinationUrl);
        if ($destinationUrl === '' || !preg_match('#^https?://#i', $destinationUrl)) {
            return ['ok' => false, 'error' => 'Destination must be a valid http(s) URL.'];
        }

        $code = trim((string) $requestedCode);
        if ($code === '') {
            $code = $this->generateUniqueCode();
        } else {
            $code = ShortLinkRepository::normalizeCode($code);
            $conflict = $this->codeConflictReason($code);
            if ($conflict !== null) {
                return ['ok' => false, 'error' => $conflict];
            }
        }

        $id = $this->links->insert($code, $destinationUrl, $label, $createdBy);

        return ['ok' => true, 'id' => $id, 'code' => $code];
    }

    /** Public redirect URL for a short code (root or prefixed per settings). */
    public function preferredPublicUrl(string $code, string $siteUrl): string
    {
        $code = ShortLinkRepository::normalizeCode($code);
        $base = rtrim($siteUrl, '/');
        if (ShortLinkConfig::rootModeEnabled()) {
            return $base . '/' . rawurlencode($code);
        }
        $prefix = ShortLinkConfig::prefixSegment();
        if ($prefix === '') {
            return $base . '/' . rawurlencode($code);
        }

        return $base . '/' . $prefix . '/' . rawurlencode($code);
    }

    public function codeConflictReason(string $code): ?string
    {
        $code = ShortLinkRepository::normalizeCode($code);
        if ($code === '') {
            return 'Short code is required.';
        }
        if (preg_match(self::CODE_PATTERN, $code) !== 1) {
            return 'Short code must be 4–12 lowercase letters or numbers (a–z, 0–9).';
        }
        if (ReservedContentSlugs::isReserved($code)) {
            return 'That short code is reserved for system routes.';
        }
        if ($this->links->codeExists($code)) {
            return 'That short code is already in use.';
        }
        $pages = new PageRepository($this->pdo);
        if ($pages->findPublishedBySlug($code) !== null) {
            return 'That short code matches an existing page slug — choose another or enable only prefixed URLs.';
        }

        return null;
    }

    private function generateUniqueCode(): string
    {
        for ($i = 0; $i < 24; $i++) {
            $code = self::randomCode(6);
            if ($this->codeConflictReason($code) === null) {
                return $code;
            }
        }

        return self::randomCode(8);
    }

    private static function randomCode(int $length): string
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
        $max = strlen($alphabet) - 1;
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }

        return $out;
    }
}
