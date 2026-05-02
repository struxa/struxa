<?php

declare(strict_types=1);

namespace App\Twig;

use App\Asset\CoreAssetResolver;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Core-owned static assets under public/ (admin CSS/JS, etc.).
 */
final class CoreAssetTwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly CoreAssetResolver $resolver,
    ) {
    }

    public function getFunctions(): array
    {
        $fn = fn (string $path): string => $this->resolver->url($path);

        return [
            new TwigFunction('core_asset', $fn),
            new TwigFunction('asset', $fn),
        ];
    }
}
