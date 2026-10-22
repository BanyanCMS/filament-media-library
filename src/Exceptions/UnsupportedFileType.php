<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Exceptions;

/**
 * The detected type is not in any kind's allow-list, or is a macro-enabled Office file.
 *
 * The message names the detected MIME type, never the filename: a filename is
 * frequently personal data.
 */
final class UnsupportedFileType extends MediaException
{
    public static function make(string $detectedMimeType): self
    {
        return new self(sprintf('Files of type [%s] are not accepted.', $detectedMimeType));
    }

    public static function macros(): self
    {
        return new self('Office files that contain macros are not accepted.');
    }
}
