<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Contracts;

/**
 * Renders the first page of a PDF as a picture, for the grid and the picker.
 *
 * Optional by design: reading a PDF needs Ghostscript behind Imagick, which
 * many hosts do not have. An implementation that cannot do it says so with
 * {@see self::available()} and returns null from {@see self::thumbnail()}; the
 * library then shows a kind icon, which is also what it shows on any failure.
 */
interface PdfThumbnailer
{
    /** Whether this implementation can work on this host at all. */
    public function available(): bool;

    /** PNG bytes of page one, `$width` pixels wide, or null when it cannot be rendered. */
    public function thumbnail(string $pdfBinary, int $width): ?string;
}
