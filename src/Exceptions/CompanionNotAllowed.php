<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Exceptions;

use Banyan\FilamentMediaLibrary\Enums\MediaKind;

/** Captions or a transcript were attached to an asset that cannot have them, or the captions file is not WebVTT. */
final class CompanionNotAllowed extends MediaException
{
    public static function captionsFor(MediaKind $kind): self
    {
        return new self(sprintf('Captions can only be attached to a video, not a %s.', $kind->value));
    }

    public static function transcriptFor(MediaKind $kind): self
    {
        return new self(sprintf('A transcript can only be added to a video or audio file, not a %s.', $kind->value));
    }

    public static function notWebVtt(): self
    {
        return new self('The captions file must be a WebVTT (.vtt) file.');
    }
}
