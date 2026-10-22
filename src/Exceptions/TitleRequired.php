<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Exceptions;

/** A non-image asset was written without a title; the title is its link text. */
final class TitleRequired extends MediaException
{
    public static function make(): self
    {
        return new self('A title is required on every media asset that is not an image.');
    }
}
