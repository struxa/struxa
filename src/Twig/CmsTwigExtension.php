<?php

declare(strict_types=1);

namespace App\Twig;

use App\Media\MediaUrlHelper;
use App\Security\CsrfToken;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class CmsTwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly MediaUrlHelper $mediaUrlHelper
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('media_url', $this->mediaUrlCallable()),
            new TwigFunction('cms_can', $this->cmsCan(...), ['needs_context' => true]),
            new TwigFunction('csrf_token', static fn (): string => CsrfToken::getOrCreate()),
        ];
    }

    /**
     * @param array<string, mixed> $context
     */
    private function cmsCan(array $context, string $slug): bool
    {
        $u = $context['cms_user'] ?? [];
        if (!is_array($u)) {
            return false;
        }
        $slugs = $u['permission_slugs'] ?? [];

        return is_array($slugs) && in_array($slug, $slugs, true);
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('media_url', $this->mediaUrlCallable()),
        ];
    }

    /** @return callable(string|int|null): string */
    private function mediaUrlCallable(): callable
    {
        return function (string|int|null $id = null): string {
            return $this->mediaUrlHelper->pathForId($id);
        };
    }
}
