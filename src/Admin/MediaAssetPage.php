<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Admin;

use BackedEnum;
use Banyan\FilamentMediaLibrary\Access\FolderAccess;
use Banyan\FilamentMediaLibrary\Access\MediaLibraryGate;
use Banyan\FilamentMediaLibrary\Admin\Concerns\GuardsRestrictedMoves;
use Banyan\FilamentMediaLibrary\Admin\Support\FolderChoices;
use Banyan\FilamentMediaLibrary\Admin\Support\MediaForms;
use Banyan\FilamentMediaLibrary\Admin\Support\MediaText;
use Banyan\FilamentMediaLibrary\Contracts\UsageResolver;
use Banyan\FilamentMediaLibrary\Enums\MediaKind;
use Banyan\FilamentMediaLibrary\Folders\MediaFolderService;
use Banyan\FilamentMediaLibrary\Governance\MediaGovernanceService;
use Banyan\FilamentMediaLibrary\MediaLibraryPlugin;
use Banyan\FilamentMediaLibrary\Models\MediaAsset;
use Banyan\FilamentMediaLibrary\Models\MediaFolder;
use Banyan\FilamentMediaLibrary\Models\MediaVersion;
use Banyan\FilamentMediaLibrary\Rendering\RenditionService;
use Banyan\FilamentMediaLibrary\Rendering\RenditionSpec;
use Banyan\FilamentMediaLibrary\Support\MediaConfig;
use Banyan\FilamentMediaLibrary\Usage\AssetUsage;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Http\UploadedFile;
use Livewire\Attributes\Locked;
use Throwable;

/**
 * One asset: preview, focal point, alt text, licence, usage and versions.
 *
 * Not registered in the navigation — it is reached from a library card — but it
 * *is* a route, so an asset is linkable. "The image on the careers page is the
 * wrong crop" is a sentence somebody has to be able to paste a URL into.
 */
class MediaAssetPage extends Page
{
    use GuardsRestrictedMoves;
    use MediaText;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-photo';

    /**
     * The uuid, not the id: this URL is pasted into tickets and chat, and an
     * autoincrement id in it would be an enumeration surface for the library.
     */
    protected static ?string $slug = 'media/{asset}';

    protected string $view = 'media-library::asset';

    /**
     * The route parameter and the id it resolved to, bound in mount(). Locked:
     * a public Livewire property can be rewritten by the client, and these two
     * decide which asset every later request reads and changes.
     */
    #[Locked]
    public string $asset = '';

    #[Locked]
    public ?int $assetId = null;

    public static function canAccess(): bool
    {
        return MediaLibraryGate::allowsBrowse();
    }

    /** Reached from a card, never from the sidebar. */
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public function mount(string $asset): void
    {
        $this->asset = $asset;

        $found = MediaAsset::query()->where('uuid', $asset)->first();
        $access = app(FolderAccess::class);

        abort_if(! $found instanceof MediaAsset, 404);

        if (! $access->canBrowse($found)) {
            // A password folder the user could unlock: send them to it, where the unlock form is.
            // Anything else (members-only, no access) is a 404, not a 403, which would confirm the file exists.
            $blocker = $found->folder instanceof MediaFolder ? $access->blockingFolder($found->folder) : null;

            abort_if(! $blocker instanceof MediaFolder || ! $access->isListed($blocker), 404);

            $this->redirect(MediaLibraryPlugin::libraryPageClass()::getUrl(['folder' => $blocker->uuid]));

            return;
        }

        $this->assetId = $found->id;
    }

    public function getTitle(): string
    {
        return $this->record()->filename ?? self::text('media.not_found');
    }

    /** May the signed-in user change this asset? Decided by the folder it is in. */
    public function canManage(): bool
    {
        $asset = $this->record();

        return $asset instanceof MediaAsset && app(FolderAccess::class)->canManage($asset);
    }

    /**
     * The asset, or null when it is gone or this user may no longer see it
     * (the folder was restricted, or their membership ended, since the page opened).
     */
    public function record(): ?MediaAsset
    {
        $asset = $this->assetId === null ? null : MediaAsset::query()->with('tags')->find($this->assetId);

        return $asset instanceof MediaAsset && app(FolderAccess::class)->canBrowse($asset) ? $asset : null;
    }

    public function previewUrl(MediaAsset $asset): string
    {
        // Contain, not crop: the focal crosshair is placed against the whole
        // image, so cropping the very preview it is set on would be circular.
        return app(RenditionService::class)->url($asset, 900, 900, RenditionSpec::FIT_CONTAIN);
    }

    public function cropPreviewUrl(MediaAsset $asset, int $width, int $height): string
    {
        return app(RenditionService::class)->url($asset, $width, $height, RenditionSpec::FIT_CROP);
    }

    public function libraryUrl(): string
    {
        return MediaLibraryPlugin::libraryPageClass()::getUrl();
    }

    /** @return list<AssetUsage> */
    public function usages(): array
    {
        return $this->assetId === null ? [] : app(UsageResolver::class)->usages($this->assetId);
    }

    /** @return list<MediaVersion> */
    public function versions(): array
    {
        return array_values($this->record()?->versions()->get()->all() ?? []);
    }

    /**
     * Persist the crosshair.
     *
     * Called from Alpine rather than through an Action because the interaction
     * is a drag on an image, not a form — but it re-checks the gate here, since
     * a Livewire method is reachable by anyone who can reach the component.
     */
    public function storeFocalPoint(float $x, float $y): void
    {
        $actorId = MediaLibraryGate::actorId();
        $asset = $this->record();

        if ($actorId === null || ! $this->canManage() || ! $asset instanceof MediaAsset) {
            return;
        }

        try {
            app(MediaGovernanceService::class)->setFocalPoint($asset, $x, $y, $actorId);

            Notification::make()->success()->title(self::text('media.focal_saved'))->send();
        } catch (Throwable $e) {
            $this->reportFailure(self::text('media.update_failed'), $e);
        }
    }

    /** @return list<Action|ActionGroup> */
    protected function getHeaderActions(): array
    {
        if (! $this->canManage()) {
            return [];
        }

        // One filled button; the rest under "More".
        return [
            ActionGroup::make([$this->moveAssetAction(), $this->editTagsAction(), $this->captionsAction(), $this->transcriptAction(), $this->replaceFileAction(), $this->deleteAssetAction()])
                ->label(self::text('media.actions.more'))
                ->icon('heroicon-m-ellipsis-vertical')
                ->color('gray')
                ->button(),
            $this->editDetailsAction(),
        ];
    }

    public function editDetailsAction(): Action
    {
        return Action::make('editDetails')
            ->label(self::text('media.actions.edit'))
            ->icon('heroicon-o-pencil-square')
            ->modalHeading(self::text('media.actions.edit_heading'))
            ->visible(fn (): bool => $this->canManage())
            ->fillForm(fn (): array => [
                'alt_text' => $this->record()?->alt_text,
                'title' => $this->record()?->title,
                'license_expires_at' => $this->record()?->license_expires_at,
            ])
            ->schema(fn (): array => MediaForms::details($this->record()?->isImage() ?? true))
            ->action(function (array $data): void {
                $actorId = MediaLibraryGate::actorId();
                $asset = $this->record();

                if ($actorId === null || ! $this->canManage() || ! $asset instanceof MediaAsset) {
                    return;
                }

                try {
                    app(MediaGovernanceService::class)->updateDetails(
                        $asset,
                        (string) ($data['alt_text'] ?? ''),
                        MediaForms::expiry($data['license_expires_at'] ?? null),
                        $actorId,
                        is_string($data['title'] ?? null) ? $data['title'] : null,
                    );

                    Notification::make()->success()->title(self::text('media.updated'))->send();
                } catch (Throwable $e) {
                    $this->reportFailure(self::text('media.update_failed'), $e);
                }
            });
    }

    /** "Reports / 2025" for the asset's folder; null when it is at the root or folders are off. */
    public function folderLabel(MediaAsset $asset): ?string
    {
        if (! MediaConfig::foldersEnabled() || $asset->folder_id === null) {
            return null;
        }

        $folder = MediaFolder::query()->find($asset->folder_id);

        return $folder instanceof MediaFolder ? (MediaFolder::pathLabels([$folder])[$folder->id] ?? null) : null;
    }

    /** File this asset in a folder (or back at the root). */
    public function moveAssetAction(): Action
    {
        return Action::make('moveAsset')
            ->label(self::text('media.folders.move_asset'))
            ->icon('heroicon-o-folder-arrow-down')
            ->color('gray')
            ->modalHeading(self::text('media.folders.move_asset_heading'))
            ->visible(fn (): bool => MediaConfig::foldersEnabled() && $this->canManage())
            ->fillForm(fn (): array => ['destination' => $this->record()?->folder?->uuid])
            ->schema([
                Select::make('destination')
                    ->label(self::text('media.folders.destination'))
                    ->placeholder(self::text('media.folders.root'))
                    ->searchable()
                    ->live()
                    ->options(fn (): array => FolderChoices::options()),
                $this->confirmUsageField(fn (Get $get): int => $this->usedFileCount($this->safeFolder($get('destination')), array_filter([$this->record()]))),
            ])
            ->action(function (array $data): void {
                $actorId = MediaLibraryGate::actorId();
                $asset = $this->record();

                if ($actorId === null || ! MediaConfig::foldersEnabled() || ! $this->canManage() || ! $asset instanceof MediaAsset) {
                    return;
                }

                try {
                    // An unknown or unusable destination fails rather than filing the asset at the root.
                    $folder = $this->folderFrom($data['destination'] ?? null);

                    if (! $this->usageConfirmed($folder, [$asset], $data)) {
                        return;
                    }

                    app(MediaFolderService::class)->moveAssets([$asset], $folder, $actorId);

                    Notification::make()->success()->title(self::text('media.folders.asset_moved'))->send();
                } catch (Throwable $e) {
                    $this->reportFailure(self::text('media.update_failed'), $e);
                }
            });
    }

    public function editTagsAction(): Action
    {
        return Action::make('editTags')
            ->label(self::text('media.actions.retag'))
            ->icon('heroicon-o-tag')
            ->color('gray')
            ->visible(fn (): bool => $this->canManage())
            ->fillForm(fn (): array => [
                'tags' => $this->record()?->tags->pluck('name')->implode(', '),
            ])
            ->schema([
                TextInput::make('tags')
                    ->label(self::text('media.field.tags'))
                    ->helperText(self::text('media.field.tags_help'))
                    ->maxLength(512),
            ])
            ->action(function (array $data): void {
                $actorId = MediaLibraryGate::actorId();
                $asset = $this->record();

                if ($actorId === null || ! $this->canManage() || ! $asset instanceof MediaAsset) {
                    return;
                }

                try {
                    app(MediaGovernanceService::class)->syncTags($asset, MediaForms::tags($data['tags'] ?? ''), $actorId);

                    Notification::make()->success()->title(self::text('media.tags_saved'))->send();
                } catch (Throwable $e) {
                    $this->reportFailure(self::text('media.update_failed'), $e);
                }
            });
    }

    /** Pick the WebVTT file that captions this video. */
    public function captionsAction(): Action
    {
        return Action::make('captions')
            ->label(self::text('media.actions.captions'))
            ->icon('heroicon-o-chat-bubble-bottom-center-text')
            ->color('gray')
            ->modalHeading(self::text('media.actions.captions_heading'))
            ->visible(fn (): bool => $this->canManage() && $this->record()?->kind === MediaKind::Video)
            ->fillForm(fn (): array => ['captions_asset_id' => $this->record()?->captions_asset_id])
            ->schema([
                Select::make('captions_asset_id')
                    ->label(self::text('media.field.captions'))
                    ->helperText(self::text('media.field.captions_help'))
                    ->searchable()
                    ->options(fn (): array => $this->captionOptions())
                    ->getSearchResultsUsing(fn (string $search): array => $this->captionOptions($search))
                    ->getOptionLabelUsing(fn (mixed $value): ?string => is_numeric($value) ? MediaAsset::query()->visibleTo()->find((int) $value)?->displayName() : null),
            ])
            ->action(function (array $data): void {
                $actorId = MediaLibraryGate::actorId();
                $asset = $this->record();

                if ($actorId === null || ! $this->canManage() || ! $asset instanceof MediaAsset) {
                    return;
                }

                try {
                    $id = $data['captions_asset_id'] ?? null;
                    $captions = is_numeric($id) ? MediaAsset::query()->visibleTo()->find((int) $id) : null;

                    app(MediaGovernanceService::class)->setCaptions($asset, $captions, $actorId);

                    Notification::make()->success()->title(self::text('media.captions_saved'))->send();
                } catch (Throwable $e) {
                    $this->reportFailure(self::text('media.update_failed'), $e);
                }
            });
    }

    /** Write the transcript of a video or audio file. */
    public function transcriptAction(): Action
    {
        return Action::make('transcript')
            ->label(self::text('media.actions.transcript'))
            ->icon('heroicon-o-document-text')
            ->color('gray')
            ->modalHeading(self::text('media.actions.transcript_heading'))
            ->visible(fn (): bool => $this->canManage() && in_array($this->record()?->kind, [MediaKind::Video, MediaKind::Audio], true))
            ->fillForm(fn (): array => ['transcript' => $this->record()?->transcript])
            ->schema([
                Textarea::make('transcript')
                    ->label(self::text('media.field.transcript'))
                    ->helperText(self::text('media.field.transcript_help'))
                    ->rows(10)
                    ->maxLength(65000),
            ])
            ->action(function (array $data): void {
                $actorId = MediaLibraryGate::actorId();
                $asset = $this->record();

                if ($actorId === null || ! $this->canManage() || ! $asset instanceof MediaAsset) {
                    return;
                }

                try {
                    app(MediaGovernanceService::class)->setTranscript($asset, is_string($data['transcript'] ?? null) ? $data['transcript'] : null, $actorId);

                    Notification::make()->success()->title(self::text('media.transcript_saved'))->send();
                } catch (Throwable $e) {
                    $this->reportFailure(self::text('media.update_failed'), $e);
                }
            });
    }

    /**
     * WebVTT files in the library, newest first.
     *
     * @return array<int, string>
     */
    private function captionOptions(?string $search = null): array
    {
        $query = MediaAsset::query()->visibleTo()->ofKind(MediaKind::Document)->where('mime_type', 'text/vtt')->orderByDesc('id')->limit(50);

        $query->search((string) $search);

        $options = [];

        foreach ($query->get() as $file) {
            $options[$file->id] = $file->displayName();
        }

        return $options;
    }

    public function replaceFileAction(): Action
    {
        return Action::make('replaceFile')
            ->label(self::text('media.actions.replace'))
            ->icon('heroicon-o-arrow-path')
            ->color('gray')
            ->modalHeading(self::text('media.actions.replace_heading'))
            ->visible(fn (): bool => $this->canManage())
            ->schema(fn (): array => MediaForms::replace($this->record()->kind ?? MediaKind::Image))
            ->action(function (array $data): void {
                $actorId = MediaLibraryGate::actorId();
                $asset = $this->record();
                $file = $data['file'] ?? null;

                if ($actorId === null || ! $this->canManage() || ! $asset instanceof MediaAsset || ! $file instanceof UploadedFile) {
                    return;
                }

                try {
                    app(MediaGovernanceService::class)->replace(
                        $asset,
                        (string) file_get_contents($file->getRealPath()),
                        $file->getClientOriginalName(),
                        (string) ($file->getMimeType() ?? 'application/octet-stream'),
                        $actorId,
                    );

                    Notification::make()->success()->title(self::text('media.replaced'))->send();
                } catch (Throwable $e) {
                    $this->reportFailure(self::text('media.replace_failed'), $e);
                }
            });
    }

    public function deleteAssetAction(): Action
    {
        return Action::make('deleteAsset')
            ->label(self::text('media.actions.delete'))
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->requiresConfirmation()
            // An asset still in use cannot be deleted (MediaRepository::delete()
            // refuses it), so the button is not offered; the usage table above
            // says where it is used instead.
            ->visible(fn (): bool => $this->canManage() && $this->usageCount() === 0)
            ->modalDescription(function (): string {
                $asset = $this->record();

                if (! $asset instanceof MediaAsset) {
                    return self::text('media.not_found');
                }

                $count = app(UsageResolver::class)->count($asset->id);

                return $count > 0
                    ? self::text('media.delete_confirm_used', ['name' => $asset->filename, 'count' => $count])
                    : self::text('media.delete_confirm', ['name' => $asset->filename]);
            })
            ->action(function (): mixed {
                $actorId = MediaLibraryGate::actorId();
                $asset = $this->record();

                if ($actorId === null || ! $this->canManage() || ! $asset instanceof MediaAsset) {
                    return null;
                }

                try {
                    app(MediaGovernanceService::class)->delete($asset, $actorId);

                    Notification::make()->success()->title(self::text('media.deleted'))->send();

                    return redirect($this->libraryUrl());
                } catch (Throwable $e) {
                    $this->reportFailure(self::text('media.delete_failed'), $e);

                    return null;
                }
            });
    }

    public function usageCount(): int
    {
        return $this->assetId === null ? 0 : app(UsageResolver::class)->count($this->assetId);
    }

    public function usageSummary(): string
    {
        return self::choice('media.usage_count', $this->usageCount());
    }

    private function reportFailure(string $title, Throwable $e): void
    {
        Notification::make()->danger()->title($title)->body($e->getMessage())->send();
    }
}
