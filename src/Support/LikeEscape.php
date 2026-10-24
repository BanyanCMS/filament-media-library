<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Support;

/**
 * Escapes LIKE wildcards so a term is matched literally.
 *
 * Use it with `escape '!'` in the SQL. A `!` works the same on MySQL,
 * PostgreSQL and SQLite, unlike a backslash.
 *
 * @internal
 */
final class LikeEscape
{
    public static function escape(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }
}
