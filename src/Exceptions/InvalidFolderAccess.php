<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Exceptions;

/** An access change the library refuses: a short password, a bad role or user id. The message never contains the password. */
final class InvalidFolderAccess extends MediaException
{
    public static function make(string $reason): self
    {
        return new self($reason);
    }
}
