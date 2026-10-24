<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Admin\Support;

use Illuminate\Support\Facades\Lang;

/**
 * Translation lookup for the media surfaces.
 *
 * A namespace of this package's own, never 'banyan': `addNamespace()` replaces
 * the path registered for a namespace, so sharing one with the admin package
 * would unload its translations, depending on provider order.
 */
trait MediaText
{
    /** @param array<string, mixed> $replace */
    protected static function text(string $key, array $replace = []): string
    {
        $namespaced = 'media-library::'.$key;

        return Lang::has($namespaced) ? __($namespaced, self::scalars($replace)) : $namespaced;
    }

    /** @param array<string, mixed> $replace */
    protected static function choice(string $key, int $count, array $replace = []): string
    {
        $namespaced = 'media-library::'.$key;

        return Lang::has($namespaced)
            ? trans_choice($namespaced, $count, [...self::scalars($replace), 'count' => $count])
            : $namespaced;
    }

    /**
     * Narrow to the scalar shape `__()` and `trans_choice()` actually accept,
     * dropping anything a caller passed that is not itself renderable into a
     * translation string.
     *
     * @param  array<string, mixed>  $replace
     * @return array<string, bool|float|int|string|null>
     */
    private static function scalars(array $replace): array
    {
        $scalars = [];

        foreach ($replace as $key => $value) {
            if ($value === null || is_bool($value) || is_float($value) || is_int($value) || is_string($value)) {
                $scalars[$key] = $value;
            }
        }

        return $scalars;
    }

    /**
     * Prefer a key another package owns, falling back to this package's copy of
     * the same string, so a headless install without the panel package still
     * reads correctly.
     */
    protected static function sharedText(string $foreignKey, string $ownKey): string
    {
        return Lang::has($foreignKey) ? __($foreignKey) : self::text($ownKey);
    }
}
