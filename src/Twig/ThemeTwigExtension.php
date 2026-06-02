<?php

declare(strict_types=1);

namespace App\Twig;

use App\Media\MediaDerivativeWidths;
use App\Theme\PublicLayoutContract;
use App\Theme\ThemeFilesystem;
use App\Theme\ThemeHttpConfig;
use App\Theme\ThemeManager;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Public frontend helpers for the active theme.
 */
final class ThemeTwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly ThemeManager $themes,
        private readonly bool $preferMinified = false,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('public_layout', static fn (): string => PublicLayoutContract::PUBLIC_ROOT),
            new TwigFunction('theme_layout', static fn (): string => PublicLayoutContract::THEME_SHELL),
            new TwigFunction(
                'media_resize_url',
                static function ($mediaId, int $width = 480): string {
                    $id = is_numeric($mediaId) ? (int) $mediaId : 0;
                    if ($id < 1 || !MediaDerivativeWidths::isAllowed($width)) {
                        return '';
                    }

                    return '/media-rs/' . $width . '/' . $id;
                }
            ),
            new TwigFunction(
                'theme_asset',
                function (string $path): string {
                    $tryPath = $path;
                    if ($this->preferMinified && preg_match('/\.(css|js)$/i', $path) === 1) {
                        $minPath = preg_replace('/\.(css|js)$/i', '.min.$1', $path, 1);
                        if (is_string($minPath) && $minPath !== $path) {
                            $minSeg = ThemeFilesystem::safeRelativePathSegments($minPath);
                            if ($minSeg !== []) {
                                $assetsRoot = $this->themes->assetsPathForActive();
                                if ($assetsRoot !== null) {
                                    $minFs = $assetsRoot . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $minSeg);
                                    if (is_file($minFs)) {
                                        $tryPath = $minPath;
                                    }
                                }
                            }
                        }
                    }

                    $segments = ThemeFilesystem::safeRelativePathSegments($tryPath);
                    if ($segments === []) {
                        return '';
                    }

                    $encoded = array_map(static fn (string $s): string => rawurlencode($s), $segments);
                    // Always root-relative so CSS/JS load from the same host, port, and scheme as the page.
                    // Absolute URLs built from request_origin break behind HTTPS proxies or when the site
                    // is opened via 127.0.0.1 vs localhost (stylesheet appears to "not load").
                    $url = ThemeHttpConfig::assetPath($encoded);

                    $assetsRoot = $this->themes->assetsPathForActive();
                    if ($assetsRoot !== null) {
                        $fsPath = $assetsRoot . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $segments);
                        if (is_file($fsPath)) {
                            $mt = @filemtime($fsPath);
                            if ($mt !== false) {
                                $url .= (str_contains($url, '?') ? '&' : '?') . 'v=' . (int) $mt;
                            }
                        }
                    }

                    return $url;
                }
            ),
        ];
    }
}
