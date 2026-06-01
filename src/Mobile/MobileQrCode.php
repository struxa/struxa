<?php

declare(strict_types=1);

namespace App\Mobile;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/** SVG QR codes for admin “Add to Struxa app” collateral. */
final class MobileQrCode
{
    public static function svg(string $data, int $size = 240): string
    {
        $data = trim($data);
        if ($data === '') {
            return '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"></svg>';
        }

        $size = max(120, min(512, $size));
        $renderer = new ImageRenderer(
            new RendererStyle($size, 2),
            new SvgImageBackEnd(),
        );

        return (new Writer($renderer))->writeString($data);
    }
}
