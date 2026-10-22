<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Scanning;

use Banyan\FilamentMediaLibrary\Contracts\FileScanner;

/** The verdict of a {@see FileScanner}. */
final readonly class ScanResult
{
    private function __construct(
        public bool $clean,
        public string $reason,
    ) {}

    public static function clean(): self
    {
        return new self(true, '');
    }

    /** @param string $reason shown to the uploader; never include the filename */
    public static function infected(string $reason): self
    {
        return new self(false, $reason);
    }
}
