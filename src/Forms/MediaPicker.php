<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Forms;

use Banyan\FilamentMediaLibrary\Access\FolderAccess;
use Banyan\FilamentMediaLibrary\Access\MediaLibraryGate;
use Banyan\FilamentMediaLibrary\Enums\MediaKind;
use Banyan\FilamentMediaLibrary\Models\MediaAsset;
use Banyan\FilamentMediaLibrary\Models\MediaFolder;
use Banyan\FilamentMediaLibrary\Pages\Concerns\RunsMediaActions;
use Banyan\FilamentMediaLibrary\Rendering\Pdf\PdfThumbnailService;
use Banyan\FilamentMediaLibrary\Rendering\RenditionService;
use Banyan\FilamentMediaLibrary\Rendering\RenditionSpec;
use Banyan\FilamentMediaLibrary\Support\MediaConfig;
use Banyan\FilamentMediaLibrary\Support\MediaText;
use Banyan\FilamentMediaLibrary\Uploads\FileTypes;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Illuminate\Http\UploadedFile;

/**
 * A form field that picks an asset from the library.
 *
 *     MediaPicker::make('hero_id')              // stores the asset id
 *     MediaPicker::make('gallery')->multiple()  // stores a list of ids
 *
 * The field stores ids only; it does not know which record it belongs to.
 * Delete protection and the licence report rely on the usages table, so after
 * saving, the host records what the record now uses:
 *
 *     app(UsageRecorder::class)->sync($record, 'hero_id', [$record->hero_id]);
 *
 * It is a plain `Select` rather than a bespoke Livewire widget: Filament's
 * searchable select already has the keyboard and screen-reader behaviour this
 * needs, and an accessible picker we maintain ourselves is one more thing to
 * keep conformant.
 *
 * Expired assets are absent from the options, because choosing is where a *new*
 * usage is created and a lapsed licence is the thing expiry exists to stop. An
 * expired asset a record already holds is still labelled, as expired, rather
 * than vanishing and leaving a blank the editor cannot explain.
 *
 * The "Upload new" suffix action appears only to users who may manage the
 * folder the upload would go to ({@see FolderAccess}).
 *
 * Folder access: the options, the search and the labels only ever
 * contain assets the signed-in user may browse, so a file in a members-only folder
 * they are not in is not in the payload. Restricted folders (members-only or
 * password) are hidden *by default* even from users who can open them, because a
 * restricted file cannot be shown on a public page:
 * {@see allowRestricted()} opts in, for intranets and back-office fields. The
 * saved value is checked against the same rule, so a hand-made request cannot
 * select a file the picker would not have offered.
 */
final class MediaPicker extends Select
{
    use MediaText;
    use RunsMediaActions;

    private const int OPTION_LIMIT = 50;

    private bool $uploadable = true;

    private bool $thumbnails = false;

    private MediaFolder|string|null $folder = null;

    private bool $allowRestricted = false;

    /** @var list<MediaKind> */
    private array $kinds = [MediaKind::Image];

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->native(false)
            ->searchable()
            ->placeholder(fn (): string => self::text($this->imagesOnly() ? 'media.picker.placeholder' : 'media.picker.placeholder_file'))
            ->searchPrompt(fn (): string => self::text($this->imagesOnly() ? 'media.picker.search' : 'media.picker.search_file'))
            ->options(fn (): array => $this->assetOptions())
            ->getSearchResultsUsing(fn (string $search): array => $this->assetOptions($search))
            ->getOptionLabelUsing(fn (mixed $value): ?string => $this->labelFor($value))
            ->getOptionLabelsUsing(fn (array $values): array => $this->labelsFor($values))
            ->rule(fn (): Closure => $this->selectionRule())
            ->suffixAction($this->uploadAction());
    }

    /**
     * Offer assets in members-only and password-protected folders too (the ones
     * the user may browse). Off by default: a restricted file cannot be shown on
     * a public page, so a public content field must not be able to pick one.
     */
    public function allowRestricted(bool $condition = true): static
    {
        $this->allowRestricted = $condition;

        return $this;
    }

    public function restrictedAllowed(): bool
    {
        return $this->allowRestricted;
    }

    /**
     * Refuse a saved value the picker would not have offered: an asset this
     * user cannot browse, or (unless {@see allowRestricted()}) a restricted one.
     *
     * @return Closure(string, mixed, Closure(string): mixed): void
     */
    private function selectionRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $ids = array_values(array_filter(array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, is_array($value) ? $value : [$value])));

            if ($ids === []) {
                return;
            }

            $query = MediaAsset::query()->visibleTo()->whereIn('id', $ids);

            if (! $this->allowRestricted) {
                $query->unrestricted();
            }

            if ($query->count() !== count(array_unique($ids))) {
                $fail(self::text('media.picker.unavailable'));
            }
        };
    }

    /**
     * Whether the signed-in user may upload into the folder the field is scoped
     * to (the root when it is not), and whether the field may use that folder at all.
     */
    private function mayUpload(): bool
    {
        $folder = null;

        if (MediaConfig::foldersEnabled() && $this->folder !== null) {
            $folder = $this->getFolder();

            // A folder that cannot be found, or that the field may not use, is not an upload target.
            if (! $folder instanceof MediaFolder || (! $this->allowRestricted && $folder->isRestricted())) {
                return false;
            }
        }

        return app(FolderAccess::class)->canManage($folder);
    }

    /** Offer or withhold the inline upload. The user's ability to manage is still checked on top. */
    public function uploadable(bool $condition = true): static
    {
        $this->uploadable = $condition;

        return $this;
    }

    /** Images only. The default, so a field declared before file kinds existed keeps its behaviour. */
    public function images(): static
    {
        return $this->kinds([MediaKind::Image]);
    }

    public function documents(): static
    {
        return $this->kinds([MediaKind::Document]);
    }

    public function videos(): static
    {
        return $this->kinds([MediaKind::Video]);
    }

    public function audio(): static
    {
        return $this->kinds([MediaKind::Audio]);
    }

    public function archives(): static
    {
        return $this->kinds([MediaKind::Archive]);
    }

    /** Every kind of file. */
    public function anyKind(): static
    {
        return $this->kinds(MediaKind::cases());
    }

    /**
     * Offer these kinds, in the options and in the inline upload.
     *
     * @param  list<MediaKind>  $kinds  an empty list falls back to images, never to everything
     */
    public function kinds(array $kinds): static
    {
        $this->kinds = $kinds === [] ? [MediaKind::Image] : array_values(array_unique($kinds, SORT_REGULAR));

        return $this;
    }

    /**
     * Offer only the assets in this folder and the folders below it (a
     * {@see MediaFolder} or its uuid), and file inline uploads there. Without it
     * the whole library is offered. A uuid that matches no folder offers nothing,
     * never everything. Ignored when `media-library.folders.enabled` is false.
     */
    public function folder(MediaFolder|string|null $folder): static
    {
        $this->folder = $folder;

        return $this;
    }

    /** The folder the field is scoped to, or null for the whole library. */
    public function getFolder(): ?MediaFolder
    {
        if (! MediaConfig::foldersEnabled() || $this->folder === null) {
            return null;
        }

        return $this->folder instanceof MediaFolder ? $this->folder : MediaFolder::findByUuid($this->folder);
    }

    /** @return list<MediaKind> */
    public function getKinds(): array
    {
        return $this->kinds;
    }

    private function imagesOnly(): bool
    {
        return $this->kinds === [MediaKind::Image];
    }

    /**
     * Show a small cropped thumbnail beside each option.
     *
     * Off by default: it turns every label into HTML, and the plain text list
     * is the cheapest thing for a screen reader and a slow connection alike.
     */
    public function thumbnails(bool $condition = true): static
    {
        $this->thumbnails = $condition;
        $this->allowHtml($condition);

        return $this;
    }

    /**
     * Selectable assets, newest first, optionally filtered by a search term.
     *
     * @return array<int, string>
     */
    private function assetOptions(?string $search = null): array
    {
        // Images unless the field opted into other kinds: a hero field must not be handed a PDF.
        // visibleTo() and unrestricted() are access rules, not filters: assets the user may not browse,
        // and (unless opted in) restricted ones, are not in the query at all.
        $query = MediaAsset::query()->visibleTo()->ofKind($this->kinds)->selectable()->orderByDesc('id')->limit(self::OPTION_LIMIT);

        if (! $this->allowRestricted) {
            $query->unrestricted();
        }

        $query->search((string) $search);

        // Scoped to a folder that cannot be found: nothing, not the whole library.
        if (MediaConfig::foldersEnabled() && $this->folder !== null) {
            $folder = $this->getFolder();

            if (! $folder instanceof MediaFolder) {
                return [];
            }

            $query->inFolder($folder, true);
        }

        $assets = $query->with('folder')->get();
        $paths = $this->folderPaths($assets);
        $options = [];

        foreach ($assets as $asset) {
            $options[$asset->id] = $this->optionLabel($asset, $paths[$asset->id] ?? null);
        }

        return $options;
    }

    private function labelFor(mixed $value): ?string
    {
        if (! is_numeric($value)) {
            return null;
        }

        // visibleTo(): an asset the user may not browse reads as missing, never by name.
        $asset = MediaAsset::query()->visibleTo()->with('folder')->find((int) $value);

        // A deleted asset says so, rather than Filament falling back to the bare id.
        return $asset instanceof MediaAsset ? $this->optionLabel($asset, $this->folderPaths([$asset])[$asset->id] ?? null) : self::text('media.picker.missing', ['id' => (int) $value]);
    }

    /**
     * @param  array<int|string, mixed>  $values
     * @return array<int, string>
     */
    private function labelsFor(array $values): array
    {
        $ids = [];

        foreach ($values as $value) {
            if (is_numeric($value)) {
                $ids[] = (int) $value;
            }
        }

        $labels = [];

        if ($ids === []) {
            return $labels;
        }

        // Not filtered by selectable(): a picked asset whose licence has since
        // lapsed must still resolve, so it can be labelled expired.
        $assets = MediaAsset::query()->visibleTo()->with('folder')->whereIn('id', $ids)->get();
        $paths = $this->folderPaths($assets);

        foreach ($assets as $asset) {
            $labels[$asset->id] = $this->optionLabel($asset, $paths[$asset->id] ?? null);
        }

        foreach (array_diff($ids, array_keys($labels)) as $missing) {
            $labels[$missing] = self::text('media.picker.missing', ['id' => $missing]);
        }

        return $labels;
    }

    /**
     * "filename — alt text" for an image, "title — Kind" for everything else,
     * plus the expiry marker when the licence has lapsed.
     */
    private function optionLabel(MediaAsset $asset, ?string $folderPath = null): string
    {
        $text = $asset->isImage()
            ? $asset->filename.' — '.$asset->alt_text
            : $asset->displayName().' — '.$asset->kind->label();

        // Two "hero.png" in different folders are told apart by where they live.
        if ($folderPath !== null) {
            $text .= ' — '.self::text('media.folders.picker_in', ['path' => $folderPath]);
        }

        if ($asset->isExpired()) {
            $text .= ' — '.self::text('media.expired');
        }

        // Held, but not offered to a field that is not allowed to use it: say so rather than hide the reason.
        if (! $this->allowRestricted && $asset->isRestricted()) {
            $text .= ' — '.self::text('media.access.restricted_marker');
        }

        if (! $this->thumbnails) {
            return $text;
        }

        // A plain string, not an HtmlString: Livewire serialises an HtmlString object to JSON and the
        // select would render "[object Object]". allowHtml() is what lets the string through as markup.
        return sprintf(
            '<span class="ml-picker__option">%s<span>%s</span></span>',
            $this->thumbnailMarkup($asset),
            e($text),
        );
    }

    /**
     * "Reports / 2025" for each filed asset, with one query for all the names.
     *
     * @param  iterable<MediaAsset>  $assets
     * @return array<int, string> asset id => folder path
     */
    private function folderPaths(iterable $assets): array
    {
        if (! MediaConfig::foldersEnabled()) {
            return [];
        }

        $folders = [];

        foreach ($assets as $asset) {
            if ($asset->folder instanceof MediaFolder) {
                $folders[$asset->folder->id] = $asset->folder;
            }
        }

        $labels = MediaFolder::pathLabels($folders);
        $paths = [];

        foreach ($assets as $asset) {
            if (isset($labels[$asset->folder_id])) {
                $paths[$asset->id] = $labels[$asset->folder_id];
            }
        }

        return $paths;
    }

    /**
     * The small picture beside an option. Classes, not inline styles, so a strict
     * style-src CSP still applies. An image is a 48px square crop (one cached
     * derivative shared by every picker); a PDF its first page when this host can
     * render one; anything else the kind's icon.
     */
    private function thumbnailMarkup(MediaAsset $asset): string
    {
        $src = $asset->isImage()
            ? app(RenditionService::class)->url($asset, 48, 48, RenditionSpec::FIT_CROP)
            : app(PdfThumbnailService::class)->url($asset, 160);

        if ($src === null) {
            return svg($asset->kind->icon(), 'ml-picker__icon', ['aria-hidden' => 'true'])->toHtml();
        }

        // The image's own alt text; a non-image's thumbnail is decorative, its name is in the label.
        return sprintf(
            '<img class="ml-picker__thumb" src="%s" alt="%s" width="48" height="48" loading="lazy">',
            e($src),
            e($asset->isImage() ? (string) $asset->alt_text : ''),
        );
    }

    /**
     * Upload without leaving the form.
     *
     * Goes through the governance service with the library's own schema, so alt
     * text stays required and the upload is audited the same as one made in the
     * library. The gate is re-checked inside the handler: `visible()` only hides
     * the button, and a Livewire action can be called without it.
     */
    private function uploadAction(): Action
    {
        return Action::make('uploadMedia')
            ->label(fn (): string => self::text('media.picker.upload'))
            ->icon('heroicon-o-arrow-up-tray')
            ->modalHeading(fn (): string => self::text('media.picker.upload_heading'))
            ->schema(fn (): array => MediaForms::upload($this->kinds))
            ->visible(fn (): bool => $this->uploadable && $this->mayUpload())
            ->action(function (array $data, Select $component): void {
                $actorId = MediaLibraryGate::actorId();

                if (! $this->uploadable || ! $this->mayUpload() || $actorId === null) {
                    return;
                }

                $file = $data['file'] ?? null;

                if (! $file instanceof UploadedFile) {
                    return;
                }

                // Refuse a kind the field does not offer before anything is stored; the browser's
                // accept list is only a hint. Detected from the bytes, the same way the guard does.
                $kind = MediaKind::fromMime(FileTypes::detect((string) $file->getRealPath(), strtolower($file->getClientOriginalExtension())));

                if ($kind !== null && ! in_array($kind, $this->kinds, true)) {
                    Notification::make()->danger()
                        ->title(self::text('media.upload_failed'))
                        ->body(self::text('media.picker.kind_rejected', ['kinds' => implode(', ', array_map(static fn (MediaKind $k): string => $k->label(), $this->kinds))]))
                        ->send();

                    return;
                }

                $this->attempt(self::text('media.uploaded'), self::text('media.upload_failed'), function () use ($file, $data, $actorId, $component): void {
                    $asset = MediaForms::storeUpload($file, $data, $actorId, $this->getFolder());

                    // Select what was just uploaded; leaving the field alone
                    // would make the action look as though it had failed.
                    $current = $component->getState();
                    $component->state($component->isMultiple()
                        ? [...(is_array($current) ? $current : []), $asset->id]
                        : $asset->id);
                });
            });
    }
}
