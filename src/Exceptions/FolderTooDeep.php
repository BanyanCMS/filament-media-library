<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Exceptions;

/** The move or create would nest folders deeper than `media-library.folders.max_depth`. */
final class FolderTooDeep extends MediaException
{
    public static function make(int $maxDepth): self
    {
        return new self(sprintf('Folders can be nested at most %d levels deep.', $maxDepth));
    }
}
