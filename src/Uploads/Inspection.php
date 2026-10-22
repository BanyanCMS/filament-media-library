<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Uploads;

use Banyan\FilamentMediaLibrary\Enums\MediaKind;

/** What {@see UploadGuard} learned about a file it let through. */
final readonly class Inspection
{
    public function __construct(
        public string $mimeType,
        public MediaKind $kind,
        public ?int $width = null,
        public ?int $height = null,
        public ?int $pageCount = null,
    ) {}

    /** @return array{0: int, 1: int}|null */
    public function dimensions(): ?array
    {
        return $this->width === null || $this->height === null ? null : [$this->width, $this->height];
    }
}
