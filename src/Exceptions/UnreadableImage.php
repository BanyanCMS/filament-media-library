<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Exceptions;

/** The uploaded bytes are not an image this install can decode. */
final class UnreadableImage extends MediaException
{
    public static function make(string $filename): self
    {
        return new self(sprintf('[%s] could not be read as an image.', $filename));
    }
}
