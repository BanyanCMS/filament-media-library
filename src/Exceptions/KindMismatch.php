<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Exceptions;

use Banyan\FilamentMediaLibrary\Enums\MediaKind;

/** A replacement would change the asset's kind, which would orphan its title, alt text and every usage. */
final class KindMismatch extends MediaException
{
    public static function make(MediaKind $expected, MediaKind $actual): self
    {
        return new self(sprintf('This asset is a %s; it cannot be replaced with a %s.', $expected->value, $actual->value));
    }
}
