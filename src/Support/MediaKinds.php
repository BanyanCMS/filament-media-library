<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Support;

use Banyan\FilamentMediaLibrary\Enums\MediaKind;

/**
 * Typed reads of `media-library.uploads.kinds`, with defaults for each kind.
 *
 * Every read falls back per key, not per array. A host that replaces the whole
 * `uploads` array or still carries the pre-kinds
 * `uploads.max_size` / `uploads.mime_types` keys therefore keeps a working,
 * image-compatible config: those two legacy keys are read as the image kind's
 * values when `uploads.kinds.image` does not say otherwise.
 */
final class MediaKinds
{
    private const array DEFAULT_MIME_TYPES = [
        'image' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'],
        'document' => [
            'application/pdf',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/vnd.oasis.opendocument.text',
            'application/vnd.oasis.opendocument.spreadsheet',
            'application/vnd.oasis.opendocument.presentation',
            'text/csv',
            'text/plain',
            'text/vtt',
        ],
        'video' => ['video/mp4', 'video/webm'],
        'audio' => ['audio/mpeg', 'audio/mp4', 'audio/wav'],
        'archive' => ['application/zip'],
    ];

    /** Kilobytes, as Filament's and Laravel's `max:` rule count. */
    private const array DEFAULT_MAX_SIZE = [
        'image' => 12288,
        'document' => 51200,
        'video' => 512000,
        'audio' => 102400,
        'archive' => 102400,
    ];

    /** @return list<string> */
    public static function mimeTypes(MediaKind $kind): array
    {
        $configured = MediaConfig::strings('uploads.kinds.'.$kind->value.'.mime_types');

        if ($configured === [] && $kind === MediaKind::Image) {
            $configured = MediaConfig::strings('uploads.mime_types');
        }

        return $configured === [] ? self::DEFAULT_MIME_TYPES[$kind->value] : $configured;
    }

    public static function maxSizeKb(MediaKind $kind): int
    {
        $fallback = $kind === MediaKind::Image
            ? MediaConfig::int('uploads.max_size', self::DEFAULT_MAX_SIZE['image'])
            : self::DEFAULT_MAX_SIZE[$kind->value];

        return max(1, MediaConfig::int('uploads.kinds.'.$kind->value.'.max_size', $fallback));
    }

    /**
     * The union of the given kinds' MIME types, for a file input's accept list.
     *
     * @param  list<MediaKind>|null  $kinds  null means every kind
     * @return list<string>
     */
    public static function acceptedMimeTypes(?array $kinds = null): array
    {
        $types = [];

        foreach ($kinds ?? MediaKind::cases() as $kind) {
            array_push($types, ...self::mimeTypes($kind));
        }

        return array_values(array_unique($types));
    }

    /**
     * The largest limit among the given kinds, in kilobytes.
     *
     * @param  list<MediaKind>|null  $kinds  null means every kind
     */
    public static function largestMaxSizeKb(?array $kinds = null): int
    {
        $largest = 1;

        foreach ($kinds ?? MediaKind::cases() as $kind) {
            $largest = max($largest, self::maxSizeKb($kind));
        }

        return $largest;
    }
}
