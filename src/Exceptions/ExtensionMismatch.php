<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Exceptions;

/** The filename extension does not agree with what the bytes are: `cv.docx` that is really HTML. */
final class ExtensionMismatch extends MediaException
{
    public static function make(string $detectedMimeType): self
    {
        return new self(sprintf('The file extension does not match its content, which is [%s].', $detectedMimeType));
    }
}
