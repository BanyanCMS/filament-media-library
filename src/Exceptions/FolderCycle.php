<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Exceptions;

/** A folder cannot move into itself or one of its own sub-folders. */
final class FolderCycle extends MediaException
{
    public static function make(): self
    {
        return new self('A folder cannot be moved into itself or one of its sub-folders.');
    }
}
