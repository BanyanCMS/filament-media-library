<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Delivery;

use Banyan\FilamentMediaLibrary\Support\MediaConfig;

/**
 * The names and paths of the delivery routes, and the cache header they send.
 *
 * Each value is read from config with its default in one place, so the
 * service provider and the code that builds URLs cannot drift apart.
 *
 * @internal
 */
final class DeliveryRoutes
{
    public const string PRIVATE_CACHE = 'private, no-store';

    public const string PUBLIC_CACHE = 'public, max-age=31536000, immutable';

    public static function renderName(): string
    {
        return MediaConfig::string('routes.render.name', 'media-library.render');
    }

    public static function renderPath(): string
    {
        return MediaConfig::string('routes.render.path', 'media/{uuid}/render');
    }

    public static function fileName(): string
    {
        return MediaConfig::string('routes.file.name', 'media-library.file');
    }

    public static function filePath(): string
    {
        return MediaConfig::string('routes.file.path', 'media/{uuid}/file');
    }

    public static function restrictedFileName(): string
    {
        return MediaConfig::string('routes.restricted_file.name', 'media-library.restricted-file');
    }

    public static function restrictedFilePath(): string
    {
        return MediaConfig::string('routes.restricted_file.path', 'media/{uuid}/restricted-file');
    }

    public static function restrictedRenderName(): string
    {
        return MediaConfig::string('routes.restricted_render.name', 'media-library.restricted-render');
    }

    public static function restrictedRenderPath(): string
    {
        return MediaConfig::string('routes.restricted_render.path', 'media/{uuid}/restricted-render');
    }

    /** The `Cache-Control` value: not shareable for a restricted file, immutable for a public one. */
    public static function cacheControl(bool $private): string
    {
        return $private ? self::PRIVATE_CACHE : self::PUBLIC_CACHE;
    }
}
