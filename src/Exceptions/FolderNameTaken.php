<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Exceptions;

/** A sibling folder already has this name (compared case-insensitively). The name is not repeated in the message. */
final class FolderNameTaken extends MediaException
{
    public static function make(): self
    {
        return new self('A folder with this name already exists here.');
    }
}
