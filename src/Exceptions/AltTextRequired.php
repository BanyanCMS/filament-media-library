<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Exceptions;

/**
 * An asset was written without alt text.
 *
 * Thrown by the repository, not only rejected by the form. The form is one
 * caller; a seeder, an import and a future API are three more, and each would
 * otherwise have to remember the rule independently.
 */
final class AltTextRequired extends MediaException
{
    public static function make(): self
    {
        return new self('Alt text is required on every media asset.');
    }
}
