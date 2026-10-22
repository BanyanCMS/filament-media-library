<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Rendering;

use Banyan\FilamentMediaLibrary\Contracts\PdfThumbnailer;
use Imagick;
use Throwable;

/**
 * First-page thumbnails through ext-imagick (which needs Ghostscript to read PDFs).
 *
 * Everything is caught: ImageMagick policies commonly forbid the PDF coder,
 * and a malformed PDF must cost the grid an icon, never a 500.
 */
final class ImagickPdfThumbnailer implements PdfThumbnailer
{
    /** Imagick is loaded and its delegates list PDF as a readable format. */
    public static function supported(): bool
    {
        if (! extension_loaded('imagick') || ! class_exists(Imagick::class)) {
            return false;
        }

        try {
            return Imagick::queryFormats('PDF') !== [];
        } catch (Throwable) {
            return false;
        }
    }

    public function available(): bool
    {
        return self::supported();
    }

    public function thumbnail(string $pdfBinary, int $width): ?string
    {
        if (! self::supported()) {
            return null;
        }

        try {
            $image = new Imagick;
            // 144 dpi keeps text legible before the resize; only page one is read.
            $image->setResolution(144, 144);
            $image->readImageBlob($pdfBinary.'[0]');
            $image->setImageBackgroundColor('white');
            $image = $image->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
            $image->setImageFormat('png');
            $image->thumbnailImage(max(1, $width), 0);

            $bytes = $image->getImageBlob();
            $image->clear();

            return $bytes === '' ? null : $bytes;
        } catch (Throwable) {
            return null;
        }
    }
}
