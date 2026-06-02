<?php

declare(strict_types=1);

namespace App\Comment;

use App\Content\ContentEntryRepository;
use App\Content\ContentType;
use App\Content\ContentTypeRepository;
use App\Page\Page;
use App\Page\PageRepository;
use App\Settings;
use PDO;

/**
 * Whether public comment UI and posting are allowed (global + per-type/page opt-out).
 */
final class CommentVisibility
{
    public static function isGloballyEnabled(): bool
    {
        $raw = strtolower(trim((string) (Settings::get('comments_enabled', '1') ?? '1')));

        return in_array($raw, ['1', 'true', 'yes', 'on'], true);
    }

    public static function isEnabledForPage(Page $page): bool
    {
        return self::isGloballyEnabled() && !$page->commentsDisabled;
    }

    public static function isEnabledForContentType(ContentType $type): bool
    {
        return self::isGloballyEnabled() && !$type->commentsDisabled;
    }

    public static function isThreadAllowed(PDO $pdo, string $threadKey): bool
    {
        if (!self::isGloballyEnabled()) {
            return false;
        }

        if (preg_match('/^page:(\d+)$/', $threadKey, $m) === 1) {
            $page = (new PageRepository($pdo))->findById((int) $m[1]);

            return $page !== null && !$page->commentsDisabled;
        }

        if (preg_match('/^entry:(\d+)$/', $threadKey, $m) === 1) {
            $entries = new ContentEntryRepository($pdo);
            $entry = $entries->findById((int) $m[1]);
            if ($entry === null) {
                return false;
            }
            $type = (new ContentTypeRepository($pdo))->findById($entry->contentTypeId);

            return $type !== null && !$type->commentsDisabled;
        }

        return false;
    }

    /**
     * @return array<string, mixed> Twig vars for comment threads (empty when disabled).
     */
    public static function twigVarsForPage(
        PDO $pdo,
        Page $page,
        string $basePath,
        bool $servedAtSiteRoot,
        ?\Psr\Http\Message\ServerRequestInterface $request,
        ?int $viewerUid = null,
    ): array {
        if (!self::isEnabledForPage($page)) {
            return ['comments_enabled_for_view' => false];
        }

        return self::loadThreadTwigVars($pdo, 'page:' . $page->id, $basePath, $request, $viewerUid);
    }

    /**
     * @return array<string, mixed> Twig vars for comment threads (empty when disabled).
     */
    public static function twigVarsForContentEntry(
        PDO $pdo,
        ContentType $type,
        int $entryId,
        string $basePath,
        ?\Psr\Http\Message\ServerRequestInterface $request,
        ?int $viewerUid = null,
    ): array {
        if (!self::isEnabledForContentType($type)) {
            return ['comments_enabled_for_view' => false];
        }

        return self::loadThreadTwigVars($pdo, 'entry:' . $entryId, $basePath, $request, $viewerUid);
    }

    /**
     * @return array<string, mixed>
     */
    private static function loadThreadTwigVars(
        PDO $pdo,
        string $threadKey,
        string $basePath,
        ?\Psr\Http\Message\ServerRequestInterface $request,
        ?int $viewerUid,
    ): array {
        $vdViewer = 0;
        $cPage = 1;
        if ($request !== null) {
            $q = $request->getQueryParams();
            $raw = isset($q['c_page']) && is_string($q['c_page']) && ctype_digit($q['c_page']) ? (int) $q['c_page'] : 1;
            $cPage = max(1, $raw);
        }
        $perRoots = max(3, min(30, (int) ($_ENV['CMS_COMMENTS_ROOTS_PER_PAGE'] ?? 10)));
        $viewer = $viewerUid !== null && $viewerUid > 0 ? $viewerUid : null;
        $pack = CommentRepository::loadThreadPagePackSafe($pdo, $threadKey, $cPage, $perRoots, $viewer);
        $commentTree = CommentThreadBuilder::toTree($pack['rows']);
        $returnTo = $basePath . ($pack['page'] > 1 ? ('?c_page=' . $pack['page']) : '');
        $total = (int) $pack['total_roots'];
        $page = (int) $pack['page'];
        $per = (int) $pack['per_page'];
        $from = $total > 0 ? (($page - 1) * $per + 1) : 0;
        $to = $total > 0 ? min($page * $per, $total) : 0;

        return [
            'comments_enabled_for_view' => true,
            'comments_thread_key' => $threadKey,
            'comments_return_to' => $returnTo,
            'comments_pager_base' => $basePath,
            'comments_pager' => [
                'page' => $page,
                'per_page' => $per,
                'total_pages' => (int) $pack['total_pages'],
                'total_roots' => $total,
                'from' => $from,
                'to' => $to,
                'base_path' => $basePath,
            ],
            'comments_thread' => $commentTree,
        ];
    }
}
