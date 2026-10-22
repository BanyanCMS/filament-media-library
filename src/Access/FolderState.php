<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Access;

/**
 * How a user stands towards a folder's contents.
 *
 * `Locked` is a password folder the user is otherwise entitled to but has not
 * unlocked: the folder is listed (so there is something to click) and its
 * contents are not. `Deny` is absent from every list.
 */
enum FolderState: int
{
    case Pass = 0;
    case Locked = 1;
    case Deny = 2;

    /** The stricter of two states: a path is only as open as its most closed folder. */
    public function worse(self $other): self
    {
        return $other->value > $this->value ? $other : $this;
    }
}
