<?php

declare(strict_types=1);

namespace App\Twig;

use App\Comment\CommentRepository;
use App\Comment\CommentVisibility;
use App\Content\ContentTypeRepository;
use App\Content\ReservedContentSlugs;
use PDO;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Public comment helpers for theme templates (e.g. blog sidebar recent comments).
 */
final class CommentTwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ContentTypeRepository $types,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('recent_content_comments', $this->recentContentComments(...)),
        ];
    }

    /**
     * @return array{
     *   enabled: bool,
     *   sort: string,
     *   comments: list<array<string, mixed>>
     * }
     */
    public function recentContentComments(string $typeSlug, string|int $limit = 5): array
    {
        $empty = ['enabled' => false, 'sort' => 'newest', 'comments' => []];
        if (!CommentVisibility::isGloballyEnabled()) {
            return $empty;
        }

        $typeSlug = trim($typeSlug);
        if ($typeSlug === '' || ReservedContentSlugs::isReserved($typeSlug)) {
            return $empty;
        }

        $type = $this->types->findBySlug($typeSlug) ?? $this->types->findBySlugCaseInsensitive($typeSlug);
        if ($type === null || !$type->hasPublicRoute || !CommentVisibility::isEnabledForContentType($type)) {
            return $empty;
        }

        $n = is_int($limit) ? $limit : (int) preg_replace('/\D+/', '', (string) $limit);
        if ($n < 1) {
            $n = 5;
        }
        $limit = max(1, min(12, $n));

        $sortRaw = isset($_GET['comment_sort']) && is_string($_GET['comment_sort'])
            ? strtolower(trim($_GET['comment_sort']))
            : 'newest';
        $sort = match ($sortRaw) {
            'oldest' => 'oldest',
            'most_liked' => 'most_liked',
            default => 'newest',
        };

        $comments = (new CommentRepository($this->pdo))->listRecentForContentType($type->id, $sort, $limit);

        return [
            'enabled' => true,
            'sort' => $sort,
            'comments' => $comments,
        ];
    }
}
