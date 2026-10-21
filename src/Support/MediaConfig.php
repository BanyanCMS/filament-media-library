<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Support;

use UnitEnum;

/**
 * Typed reads of the `media-library` config, so no caller has to narrow
 * `mixed` itself and every fallback lives in one place.
 */
final class MediaConfig
{
    /** @param 'assets'|'versions'|'tags'|'asset_tag'|'usages' $name */
    public static function table(string $name): string
    {
        return self::string('tables.'.$name, 'media_'.$name);
    }

    public static function string(string $key, string $fallback): string
    {
        $value = config('media-library.'.$key, $fallback);

        return is_string($value) && $value !== '' ? $value : $fallback;
    }

    public static function int(string $key, int $fallback): int
    {
        $value = config('media-library.'.$key, $fallback);

        return is_numeric($value) ? (int) $value : $fallback;
    }

    public static function bool(string $key, bool $fallback): bool
    {
        $value = config('media-library.'.$key, $fallback);

        return is_bool($value) ? $value : $fallback;
    }

    /** @return list<string> */
    public static function strings(string $key): array
    {
        $value = config('media-library.'.$key, []);

        return is_array($value) ? array_values(array_filter($value, is_string(...))) : [];
    }

    public static function navigationGroup(): string|UnitEnum|null
    {
        $group = config('media-library.navigation.group');

        return is_string($group) || $group instanceof UnitEnum ? $group : null;
    }

    public static function expiryWarningDays(): int
    {
        return max(1, self::int('expiry_warning_days', 30));
    }
}
