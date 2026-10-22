<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Access;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Who is asking, with the library abilities read once. Gate callbacks can be
 * expensive (permission packages), and a visibility list asks the same question
 * of every folder.
 *
 * @internal
 */
final readonly class AccessContext
{
    public function __construct(
        public Authenticatable $user,
        public bool $manageAll,
        public bool $browse,
        public bool $manage,
    ) {}
}
