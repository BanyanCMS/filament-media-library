<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Admin\Support;

use Banyan\FilamentMediaLibrary\Support\MediaConfig;
use DateTimeImmutable;
use DateTimeInterface;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Throwable;

/**
 * The form schemas the library and the asset page share.
 *
 * Alt text carries `->required()` in every schema here, and the column behind
 * it is NOT NULL, and the repository throws without it. Three layers is not
 * belt-and-braces: the form stops the mistake, the repository stops the
 * non-form caller, and the column stops both from ever having been wrong
 * historically (docs/ux/0004).
 */
final class MediaForms
{
    use MediaText;

    /** @return list<Component> */
    public static function upload(): array
    {
        return [
            FileUpload::make('file')
                ->label(self::text('media.field.file'))
                ->image()
                ->required()
                // Not stored by Filament: the repository owns pathing, version
                // ordinals and the disk, so it takes the bytes and decides.
                ->storeFiles(false)
                ->maxSize(self::maxSize())
                ->acceptedFileTypes(self::mimeTypes()),
            ...self::details(),
            TextInput::make('tags')
                ->label(self::text('media.field.tags'))
                ->helperText(self::text('media.field.tags_help'))
                ->maxLength(512),
        ];
    }

    /** @return list<Component> */
    public static function details(): array
    {
        return [
            TextInput::make('alt_text')
                ->label(self::text('media.field.alt_text'))
                ->helperText(self::text('media.field.alt_text_help'))
                ->required()
                ->maxLength(512),
            DateTimePicker::make('license_expires_at')
                ->label(self::text('media.field.license_expires_at'))
                ->helperText(self::text('media.field.license_expires_at_help'))
                ->seconds(false),
        ];
    }

    /** @return list<Component> */
    public static function replace(): array
    {
        return [
            FileUpload::make('file')
                ->label(self::text('media.field.file'))
                ->helperText(self::text('media.actions.replace_help'))
                ->image()
                ->required()
                ->storeFiles(false)
                ->maxSize(self::maxSize())
                ->acceptedFileTypes(self::mimeTypes()),
        ];
    }

    public static function expiry(mixed $value): ?DateTimeInterface
    {
        if ($value instanceof DateTimeInterface) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * A comma separated string as a list of names.
     *
     * @return list<string>
     */
    public static function tags(mixed $value): array
    {
        if (! is_string($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(trim(...), explode(',', $value)),
            static fn (string $name): bool => $name !== '',
        ));
    }

    private static function maxSize(): int
    {
        return MediaConfig::int('uploads.max_size', 12288);
    }

    /** @return list<string> */
    private static function mimeTypes(): array
    {
        $out = MediaConfig::strings('uploads.mime_types');

        return $out === [] ? ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'] : $out;
    }
}
