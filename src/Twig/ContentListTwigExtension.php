<?php

declare(strict_types=1);

namespace App\Twig;

use App\Content\ContentEntryValueRepository;
use App\Content\ContentFieldRepository;
use App\Content\List\ContentListQueryRunner;
use App\Content\List\ContentListRepository;
use App\Content\List\ContentListService;
use App\Content\ContentTypeRepository;
use App\Content\PublicContentIndexCardBuilder;
use App\Media\MediaUrlHelper;
use PDO;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Run a saved content list in theme templates: content_list('top-reviews', 12).
 */
final class ContentListTwigExtension extends AbstractExtension
{
    private readonly ContentListService $service;

    public function __construct(PDO $pdo)
    {
        $fields = new ContentFieldRepository($pdo);
        $this->service = new ContentListService(
            $pdo,
            new ContentListRepository($pdo),
            new ContentTypeRepository($pdo),
            new ContentListQueryRunner($pdo, $fields),
            new PublicContentIndexCardBuilder($fields, new ContentEntryValueRepository($pdo), new MediaUrlHelper($pdo)),
        );
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('content_list', $this->contentList(...)),
        ];
    }

    /**
     * @return array{type: ?\App\Content\ContentType, list: ?array<string, mixed>, entries: list<array<string, mixed>>, total: int, page: int}
     */
    public function contentList(string $slug, int $limit = 0, int $page = 1): array
    {
        $slug = trim($slug);
        if ($slug === '') {
            return ['type' => null, 'list' => null, 'entries' => [], 'total' => 0, 'page' => 1];
        }

        $pack = $this->service->runForTwig($slug, max(1, $page));
        if ($pack === null) {
            return ['type' => null, 'list' => null, 'entries' => [], 'total' => 0, 'page' => 1];
        }

        $entries = $pack['cards'];
        if ($limit > 0) {
            $entries = array_slice($entries, 0, max(1, min(50, $limit)));
        }

        return [
            'type' => $pack['type'],
            'list' => $pack['list'],
            'entries' => $entries,
            'total' => $pack['total'],
            'page' => $pack['page'],
        ];
    }
}
