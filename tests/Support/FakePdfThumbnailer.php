<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Tests\Support;

use Banyan\FilamentMediaLibrary\Contracts\PdfThumbnailer;

/** Counts calls, so a test can prove the second request came from the cache. */
final class FakePdfThumbnailer implements PdfThumbnailer
{
    public int $calls = 0;

    public function __construct(private readonly bool $works = true) {}

    public function available(): bool
    {
        return true;
    }

    public function thumbnail(string $pdfBinary, int $width): ?string
    {
        $this->calls++;

        return $this->works ? Images::splitPng($width, (int) ($width * 1.4)) : null;
    }
}
