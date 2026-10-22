<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Enums;

use Banyan\FilamentMediaLibrary\Support\MediaKinds;

/**
 * What sort of file an asset is.
 *
 * Derived from the detected MIME type at upload and never user-set: a kind
 * the uploader could choose would be a kind the uploader could lie about, and
 * the kind decides which validation, which preview and which required text
 * (alt text or title) applies.
 */
enum MediaKind: string
{
    case Image = 'image';
    case Document = 'document';
    case Video = 'video';
    case Audio = 'audio';
    case Archive = 'archive';

    /** The kind whose configured allow-list names this MIME type, or null when none does. */
    public static function fromMime(string $mimeType): ?self
    {
        foreach (self::cases() as $kind) {
            if (in_array($mimeType, MediaKinds::mimeTypes($kind), true)) {
                return $kind;
            }
        }

        return null;
    }

    public function label(): string
    {
        return __('media-library::media.kind.'.$this->value);
    }

    public function icon(): string
    {
        return match ($this) {
            self::Image => 'heroicon-o-photo',
            self::Document => 'heroicon-o-document',
            self::Video => 'heroicon-o-film',
            self::Audio => 'heroicon-o-musical-note',
            self::Archive => 'heroicon-o-archive-box',
        };
    }
}
