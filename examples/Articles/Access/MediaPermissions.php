<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Examples\Articles\Access;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;

/**
 * Maps the library's two abilities onto this app's own idea of an editor.
 *
 * Here an editor is a user whose email is in `example-articles.editors`. Your
 * app would read a role, a column or a permission package instead; the library
 * only asks the Gate.
 *
 * `media.view` is left undefined on purpose: any signed-in user may browse and
 * pick. `media.manage` (upload, replace, refocus, retag, delete) is defined,
 * because an undefined manage ability means nobody may manage.
 */
final class MediaPermissions
{
    public static function define(): void
    {
        Gate::define('media.manage', self::isEditor(...));
    }

    public static function isEditor(?Authenticatable $user): bool
    {
        $email = $user === null ? null : data_get($user, 'email');

        return is_string($email) && in_array(strtolower($email), self::editors(), true);
    }

    /** @return list<string> */
    public static function editors(): array
    {
        $editors = config('example-articles.editors', []);

        return is_array($editors) ? array_values(array_map(fn (mixed $e): string => strtolower((string) (is_scalar($e) ? $e : '')), $editors)) : [];
    }
}
