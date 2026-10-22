<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Exceptions;

/** A folder name was empty, too long, or held a slash or control character. */
final class InvalidFolderName extends MediaException
{
    public static function make(string $reason): self
    {
        return new self($reason);
    }
}
