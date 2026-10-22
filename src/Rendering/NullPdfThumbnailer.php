<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Rendering;

use Banyan\FilamentMediaLibrary\Contracts\PdfThumbnailer;

/** The default when no PDF engine is installed: no thumbnails, so surfaces fall back to a kind icon. */
final class NullPdfThumbnailer implements PdfThumbnailer
{
    public function available(): bool
    {
        return false;
    }

    public function thumbnail(string $pdfBinary, int $width): ?string
    {
        return null;
    }
}
