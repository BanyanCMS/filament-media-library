<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Forms;

use Banyan\FilamentMediaLibrary\Access\MediaLibraryGate;
use Banyan\FilamentMediaLibrary\Admin\Support\MediaForms;
use Banyan\FilamentMediaLibrary\Admin\Support\MediaText;
use Banyan\FilamentMediaLibrary\Governance\MediaGovernanceService;
use Banyan\FilamentMediaLibrary\Models\MediaAsset;
use Banyan\FilamentMediaLibrary\Rendering\RenditionService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Illuminate\Http\UploadedFile;
use Throwable;

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
 * library. Browsing is not checked here: whoever can open the form that holds
 * the field is already choosing which assets it shows.
 */
final class MediaPicker extends Select
{
    use MediaText;

    private const OPTION_LIMIT = 50;

    private bool $uploadable = true;

    private bool $thumbnails = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->native(false)
            ->searchable()
            ->placeholder(fn (): string => self::text('media.picker.placeholder'))
            ->searchPrompt(fn (): string => self::text('media.picker.search'))
            ->options(fn (): array => $this->assetOptions())
            ->getSearchResultsUsing(fn (string $search): array => $this->assetOptions($search))
            ->getOptionLabelUsing(fn (mixed $value): ?string => $this->labelFor($value))
            ->getOptionLabelsUsing(fn (array $values): array => $this->labelsFor($values))
            ->suffixAction($this->uploadAction());
    }

    /** Offer or withhold the inline upload. The user's ability to manage is still checked on top. */
    public function uploadable(bool $condition = true): static
    {
        $this->uploadable = $condition;

        return $this;
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
        $query = MediaAsset::query()->selectable()->orderByDesc('id')->limit(self::OPTION_LIMIT);

        $term = trim((string) $search);

        if ($term !== '') {
            $like = '%'.addcslashes($term, '\\%_').'%';

            $query->where(function ($q) use ($like): void {
                $q->where('filename', 'like', $like)->orWhere('alt_text', 'like', $like);
            });
        }

        $options = [];

        foreach ($query->get() as $asset) {
            $options[$asset->id] = $this->optionLabel($asset);
        }

        return $options;
    }

    private function labelFor(mixed $value): ?string
    {
        if (! is_numeric($value)) {
            return null;
        }

        $asset = MediaAsset::query()->find((int) $value);

        return $asset instanceof MediaAsset ? $this->optionLabel($asset) : null;
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
        foreach (MediaAsset::query()->whereIn('id', $ids)->get() as $asset) {
            $labels[$asset->id] = $this->optionLabel($asset);
        }

        return $labels;
    }

    /** "filename — alt text", plus the expiry marker when the licence has lapsed. */
    private function optionLabel(MediaAsset $asset): string
    {
        $text = $asset->filename.' — '.$asset->alt_text;

        if ($asset->isExpired()) {
            $text .= ' — '.self::text('media.expired');
        }

        if (! $this->thumbnails) {
            return $text;
        }

        // Classes, not inline styles, so a strict style-src CSP still applies.
        // 48px square crop: small enough to be one cached derivative shared by
        // every picker, and the alt text is the asset's own, never decorative.
        $src = app(RenditionService::class)->url($asset, 48, 48, 'crop');

        // A plain string, not an HtmlString: Livewire serialises an HtmlString object to JSON and the
        // select would render "[object Object]". allowHtml() is what lets the string through as markup.
        return sprintf(
            '<span class="bn-media-picker__option"><img class="bn-media-picker__thumb" src="%s" alt="%s" width="48" height="48" loading="lazy"><span>%s</span></span>',
            e($src),
            e($asset->alt_text),
            e($text),
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
            ->schema(MediaForms::upload())
            ->visible(fn (): bool => $this->uploadable && MediaLibraryGate::allowsManage())
            ->action(function (array $data, Select $component): void {
                $actorId = MediaLibraryGate::actorId();

                if (! $this->uploadable || ! MediaLibraryGate::allowsManage() || $actorId === null) {
                    return;
                }

                $file = $data['file'] ?? null;

                if (! $file instanceof UploadedFile) {
                    return;
                }

                try {
                    $service = app(MediaGovernanceService::class);

                    $asset = $service->upload(
                        (string) file_get_contents($file->getRealPath()),
                        $file->getClientOriginalName(),
                        (string) ($file->getMimeType() ?? 'application/octet-stream'),
                        (string) $data['alt_text'],
                        $actorId,
                        MediaForms::expiry($data['license_expires_at'] ?? null),
                    );

                    if (filled($data['tags'] ?? null)) {
                        $service->syncTags($asset, MediaForms::tags($data['tags']), $actorId);
                    }

                    // Select what was just uploaded; leaving the field alone
                    // would make the action look as though it had failed.
                    $current = $component->getState();
                    $component->state($component->isMultiple()
                        ? [...(is_array($current) ? $current : []), $asset->id]
                        : $asset->id);

                    Notification::make()->success()->title(self::text('media.uploaded'))->send();
                } catch (Throwable $e) {
                    Notification::make()->danger()->title(self::text('media.upload_failed'))->body($e->getMessage())->send();
                }
            });
    }
}
