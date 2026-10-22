<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Exceptions;

/** A folder with sub-folders or assets is never deleted implicitly. */
final class FolderNotEmpty extends MediaException
{
    public static function make(): self
    {
        return new self('The folder is not empty. Move or delete its contents first.');
    }
}
