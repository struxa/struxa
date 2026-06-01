<?php

declare(strict_types=1);

namespace App\Twig;

use App\Content\ContentEntryRefResolver;
use App\Content\ContentEntryReferenceIds;
use App\Content\ContentEntryRepository;
use App\Content\ContentTypeRepository;
use App\Settings\SiteUrlResolver;
use PDO;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Resolve entry_refs field values in theme templates: entry_refs_resolve('[12,34]').
 */
final class ContentEntryRefsTwigExtension extends AbstractExtension
{
    private readonly ContentEntryRefResolver $resolver;

    public function __construct(PDO $pdo)
    {
        $this->resolver = new ContentEntryRefResolver(
            new ContentEntryRepository($pdo),
            new ContentTypeRepository($pdo),
        );
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('entry_refs_resolve', $this->resolve(...)),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function resolve(string $storedValue): array
    {
        $ids = ContentEntryReferenceIds::parse($storedValue);

        return $this->resolver->resolvePublic($ids, SiteUrlResolver::resolve());
    }
}
