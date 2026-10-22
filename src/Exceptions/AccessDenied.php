<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Exceptions;

/**
 * Raised when code asks for a URL to a restricted file on behalf of someone who
 * may not browse its folder. Thrown rather than answered with null or an empty
 * string: an `<img src="">` that quietly resolves to the current page is a worse
 * failure than a loud one, and a restricted URL must never be issued "just in case".
 */
final class AccessDenied extends MediaException
{
    public static function make(): self
    {
        return new self('You do not have access to this file.');
    }
}
