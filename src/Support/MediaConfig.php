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
    /** @param 'assets'|'versions'|'tags'|'asset_tag'|'usages'|'folders'|'folder_members' $name */
    public static function table(string $name): string
    {
        return self::string('tables.'.$name, 'media_'.$name);
    }

    public static function string(string $key, string $fallback): string
    {
        $value = config('media-library.'.$key, $fallback);

        return is_string($value) && $value !== '' ? $value : $fallback;
    }

    public static function nullableString(string $key): ?string
    {
        $value = config('media-library.'.$key);

        return is_string($value) && $value !== '' ? $value : null;
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

    /** Folders are on unless a host turns them off; off means a flat library, everything at the root. */
    public static function foldersEnabled(): bool
    {
        return self::bool('folders.enabled', true);
    }

    public static function maxFolderDepth(): int
    {
        return max(1, self::int('folders.max_depth', 8));
    }

    public static function expiryWarningDays(): int
    {
        return max(1, self::int('expiry_warning_days', 30));
    }
}
