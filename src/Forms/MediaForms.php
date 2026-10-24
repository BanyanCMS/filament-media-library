<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Forms;

use Banyan\FilamentMediaLibrary\Enums\MediaKind;
use Banyan\FilamentMediaLibrary\Governance\MediaGovernanceService;
use Banyan\FilamentMediaLibrary\Models\MediaAsset;
use Banyan\FilamentMediaLibrary\Models\MediaFolder;
use Banyan\FilamentMediaLibrary\Support\MediaKinds;
use Banyan\FilamentMediaLibrary\Support\MediaText;
use DateTimeImmutable;
use DateTimeInterface;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\File\File;
use Throwable;

/**
 * The form schemas the library and the asset page share.
 *
 * Alt text carries `->required()` wherever the asset is, or can only be, an
 * image, and the repository throws without it. A title is the
 * same rule for every other kind. The form stops the mistake; the
 * repository stops the non-form caller. Where the kind is not known until a
 * file is chosen, the form states the rule in its help text and relies on the
 * repository's message.
 */
final class MediaForms
{
    use MediaText;

    /**
     * @param  list<MediaKind>|null  $kinds  the kinds this form accepts; null means every kind
     * @return list<Component>
     */
    public static function upload(?array $kinds = null): array
    {
        $imageOnly = $kinds === [MediaKind::Image];
        // No image among the kinds: every file needs a title, and alt text has no place in the form.
        $noImages = $kinds !== null && ! in_array(MediaKind::Image, $kinds, true);

        $file = FileUpload::make('file')
            ->label(self::text($imageOnly ? 'media.field.file' : 'media.field.any_file'))
            ->required()
            // Not stored by Filament: the repository owns pathing, version
            // ordinals and the disk, so it takes the bytes and decides.
            ->storeFiles(false)
            ->maxSize(MediaKinds::largestMaxSizeKb($kinds))
            // Laravel's own message lists every accepted MIME type; one short sentence serves an editor better.
            ->validationMessages(['mimetypes' => self::text('media.field.file_type_refused')])
            // `live()` so the alt text / title requirement can follow the chosen file.
            ->live(! $imageOnly);

        // Before the type list: image() sets `image/*`, which the configured list must override.
        if ($imageOnly) {
            $file->image();
        }

        $file->acceptedFileTypes(MediaKinds::acceptedMimeTypes($kinds));

        return [
            $file,
            ...($imageOnly ? [] : [self::title($noImages ? true : fn (Get $get): bool => ! self::isImageUpload($get('file')))]),
            ...array_values(array_filter(
                self::details($imageOnly ? true : null),
                static fn (Component $component): bool => ! ($noImages && $component instanceof TextInput && $component->getName() === 'alt_text'),
            )),
            TextInput::make('tags')
                ->label(self::text('media.field.tags'))
                ->helperText(self::text('media.field.tags_help'))
                ->maxLength(512),
        ];
    }

    /**
     * @param  bool|null  $image  true: an image (alt text); false: another kind (title); null: decided by the chosen file
     * @return list<Component>
     */
    public static function details(?bool $image = true): array
    {
        $alt = TextInput::make('alt_text')
            ->label(self::text('media.field.alt_text'))
            ->helperText(self::text('media.field.alt_text_help'))
            ->maxLength(512);

        if ($image === null) {
            $alt->required(fn (Get $get): bool => self::isImageUpload($get('file')));
        } else {
            $alt->required($image);
        }

        return [
            ...($image === false ? [self::title(true)] : []),
            $alt,
            DateTimePicker::make('license_expires_at')
                ->label(self::text('media.field.license_expires_at'))
                ->helperText(self::text('media.field.license_expires_at_help'))
                ->seconds(false),
        ];
    }

    /**
     * @param  MediaKind  $kind  a replacement keeps the asset's kind
     * @return list<Component>
     */
    public static function replace(MediaKind $kind = MediaKind::Image): array
    {
        $file = FileUpload::make('file')
            ->label(self::text($kind === MediaKind::Image ? 'media.field.file' : 'media.field.any_file'))
            ->helperText(self::text('media.actions.replace_help'))
            ->required()
            ->storeFiles(false)
            ->maxSize(MediaKinds::maxSizeKb($kind))
            ->validationMessages(['mimetypes' => self::text('media.field.file_type_refused')]);

        if ($kind === MediaKind::Image) {
            $file->image();
        }

        return [$file->acceptedFileTypes(MediaKinds::acceptedMimeTypes([$kind]))];
    }

    /**
     * Store the file from an upload form, with its alt text, title, licence date and tags.
     *
     * The caller has already checked who may upload. The service still checks
     * the file itself.
     *
     * @param  array<string, mixed>  $data  the submitted upload form
     */
    public static function storeUpload(UploadedFile $file, array $data, string $actorId, ?MediaFolder $folder = null): MediaAsset
    {
        $service = app(MediaGovernanceService::class);
        $altText = $data['alt_text'] ?? '';

        $asset = $service->upload(
            self::contents($file),
            $file->getClientOriginalName(),
            is_scalar($altText) ? (string) $altText : '',
            $actorId,
            self::expiry($data['license_expires_at'] ?? null),
            is_string($data['title'] ?? null) ? $data['title'] : null,
            $folder,
        );

        if (filled($data['tags'] ?? null)) {
            $service->syncTags($asset, self::tags($data['tags']), $actorId);
        }

        return $asset;
    }

    /** Put the file from a replace form on the asset as a new version. */
    public static function storeReplacement(MediaAsset $asset, UploadedFile $file, string $actorId): void
    {
        app(MediaGovernanceService::class)->replace($asset, self::contents($file), $file->getClientOriginalName(), $actorId);
    }

    private static function contents(UploadedFile $file): string
    {
        return (string) file_get_contents($file->getRealPath());
    }

    /** @param  bool|\Closure(Get): bool  $required */
    private static function title(bool|\Closure $required): TextInput
    {
        return TextInput::make('title')
            ->label(self::text('media.field.title'))
            ->helperText(self::text('media.field.title_help'))
            ->required($required)
            ->maxLength(255);
    }

    /**
     * Whether the file chosen in the upload field is an image.
     *
     * No file yet counts as an image, so the stricter alt-text rule shows
     * until the editor picks something else.
     */
    public static function isImageUpload(mixed $state): bool
    {
        $file = is_array($state) ? ($state[array_key_first($state)] ?? null) : $state;

        return ! $file instanceof File || str_starts_with((string) $file->getMimeType(), 'image/');
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
}
