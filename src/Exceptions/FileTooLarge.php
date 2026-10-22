<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Exceptions;

use Banyan\FilamentMediaLibrary\Enums\MediaKind;

/** The file is over its kind's `uploads.kinds.*.max_size`. */
final class FileTooLarge extends MediaException
{
    public static function make(MediaKind $kind, int $limitKb): self
    {
        return new self(sprintf('A %s file may be at most %d KB.', $kind->value, $limitKb));
    }
}
