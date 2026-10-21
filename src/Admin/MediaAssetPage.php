<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Admin;

use BackedEnum;
use Banyan\FilamentMediaLibrary\Access\MediaLibraryGate;
use Banyan\FilamentMediaLibrary\Admin\Support\MediaForms;
use Banyan\FilamentMediaLibrary\Admin\Support\MediaText;
use Banyan\FilamentMediaLibrary\Contracts\UsageResolver;
use Banyan\FilamentMediaLibrary\Governance\MediaGovernanceService;
use Banyan\FilamentMediaLibrary\Models\MediaAsset;
use Banyan\FilamentMediaLibrary\Models\MediaVersion;
use Banyan\FilamentMediaLibrary\Rendering\RenditionService;
use Banyan\FilamentMediaLibrary\Rendering\RenditionSpec;
use Banyan\FilamentMediaLibrary\Usage\AssetUsage;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Http\UploadedFile;
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
    use MediaText;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-photo';

    /**
     * The uuid, not the id: this URL is pasted into tickets and chat, and an
     * autoincrement id in it would be an enumeration surface for the library.
     */
    protected static ?string $slug = 'media/{asset}';

    protected string $view = 'media-library::asset';

    /** The route parameter, bound in mount(). */
    public string $asset = '';

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

        $id = MediaAsset::query()->where('uuid', $asset)->value('id');
        $this->assetId = is_numeric($id) ? (int) $id : null;

        abort_if($this->assetId === null, 404);
    }

    public function getTitle(): string
    {
        return $this->record()->filename ?? self::text('media.not_found');
    }

    public function canManage(): bool
    {
        return MediaLibraryGate::allowsManage();
    }

    public function record(): ?MediaAsset
    {
        return $this->assetId === null ? null : MediaAsset::query()->with('tags')->find($this->assetId);
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
        return MediaLibraryPage::getUrl();
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

        if ($actorId === null || ! MediaLibraryGate::allowsManage() || ! $asset instanceof MediaAsset) {
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
            ActionGroup::make([$this->editTagsAction(), $this->replaceFileAction(), $this->deleteAssetAction()])
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
            ->fillForm(fn (): array => [
                'alt_text' => $this->record()?->alt_text,
                'license_expires_at' => $this->record()?->license_expires_at,
            ])
            ->schema(MediaForms::details())
            ->action(function (array $data): void {
                $actorId = MediaLibraryGate::actorId();
                $asset = $this->record();

                if ($actorId === null || ! MediaLibraryGate::allowsManage() || ! $asset instanceof MediaAsset) {
                    return;
                }

                try {
                    app(MediaGovernanceService::class)->updateDetails(
                        $asset,
                        (string) $data['alt_text'],
                        MediaForms::expiry($data['license_expires_at'] ?? null),
                        $actorId,
                    );

                    Notification::make()->success()->title(self::text('media.updated'))->send();
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

                if ($actorId === null || ! MediaLibraryGate::allowsManage() || ! $asset instanceof MediaAsset) {
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

    public function replaceFileAction(): Action
    {
        return Action::make('replaceFile')
            ->label(self::text('media.actions.replace'))
            ->icon('heroicon-o-arrow-path')
            ->color('gray')
            ->modalHeading(self::text('media.actions.replace_heading'))
            ->schema(MediaForms::replace())
            ->action(function (array $data): void {
                $actorId = MediaLibraryGate::actorId();
                $asset = $this->record();
                $file = $data['file'] ?? null;

                if ($actorId === null || ! MediaLibraryGate::allowsManage() || ! $asset instanceof MediaAsset || ! $file instanceof UploadedFile) {
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

                if ($actorId === null || ! MediaLibraryGate::allowsManage() || ! $asset instanceof MediaAsset) {
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
