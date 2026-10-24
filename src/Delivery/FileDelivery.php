<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Delivery;

use Banyan\FilamentMediaLibrary\Enums\MediaKind;
use Banyan\FilamentMediaLibrary\Exceptions\AccessDenied;
use Banyan\FilamentMediaLibrary\Models\MediaAsset;
use Illuminate\Support\Str;

/**
 * Addresses and response headers of the file route.
 *
 * Kept apart from the controller so the URL a page embeds and the headers the
 * route answers with are decided in one place, and so a host can build the
 * same disposition for its own delivery layer.
 */
final class FileDelivery
{
    /**
     * A signed URL for the asset's current file.
     *
     * Like a rendition URL it carries the version (`v`, covered by the
     * signature), so the year-long immutable cache header is honest: a replaced
     * file is a new address. `download=1` is part of the signature too, so a
     * link cannot be flipped between inline and attachment by editing it.
     *
     * A file in a members-only or password-protected folder gets a short-lived
     * address on the session-checked route instead (see {@see SignedUrls}).
     *
     * @throws AccessDenied for a restricted file the signed-in user may not browse
     */
    public static function url(MediaAsset $asset, bool $download = false): string
    {
        return SignedUrls::make(
            $asset,
            DeliveryRoutes::fileName(),
            DeliveryRoutes::restrictedFileName(),
            array_filter([
                'uuid' => $asset->uuid,
                'v' => $asset->current_version_id ?? 0,
                'download' => $download ? 1 : null,
            ], static fn (mixed $value): bool => $value !== null),
        );
    }

    /** Whether a browser may show this kind of file in place: images, video, audio and PDF. */
    public static function isInline(MediaAsset $asset): bool
    {
        return in_array($asset->kind, [MediaKind::Image, MediaKind::Video, MediaKind::Audio], true)
            || $asset->isPdf();
    }

    /**
     * An RFC 6266 Content-Disposition: a plain ASCII `filename` for old clients and
     * `filename*` (RFC 5987) carrying the real name.
     */
    public static function disposition(MediaAsset $asset, bool $download = false): string
    {
        $type = ! $download && self::isInline($asset) ? 'inline' : 'attachment';
        $name = self::filename($asset);

        return sprintf('%s; filename="%s"; filename*=UTF-8\'\'%s', $type, self::asciiFallback($name), rawurlencode($name));
    }

    /**
     * What the file is called on the visitor's disk: the title (the name a person gave it)
     * plus the stored extension, or the stored filename when there is no title.
     *
     * Path separators, quotes, control characters and percent signs are removed:
     * the name ends up in a header and, after download, on someone's file system.
     */
    public static function filename(MediaAsset $asset): string
    {
        $extension = strtolower(pathinfo($asset->filename, PATHINFO_EXTENSION));
        $title = self::clean((string) $asset->title);

        if ($title !== '') {
            $base = $title;
            $suffix = $extension === '' ? '' : '.'.$extension;

            // A title that already ends in the extension is not doubled.
            return $suffix !== '' && str_ends_with(strtolower($base), $suffix) ? $base : $base.$suffix;
        }

        $stored = self::clean($asset->filename);

        return $stored === '' ? 'download' : $stored;
    }

    private static function clean(string $name): string
    {
        $name = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name);
        $name = str_replace(['/', '\\', '"', '%', ':', '*', '?', '<', '>', '|'], '-', $name);
        $name = trim((string) preg_replace('/\s+/u', ' ', $name), " .\t");

        return Str::limit($name, 150, '');
    }

    private static function asciiFallback(string $name): string
    {
        $ascii = trim((string) preg_replace('/[^A-Za-z0-9._ -]+/', '_', Str::ascii($name)));

        return $ascii === '' ? 'download' : $ascii;
    }
}
