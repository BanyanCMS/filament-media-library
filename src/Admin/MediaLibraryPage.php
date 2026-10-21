<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Admin;

use BackedEnum;
use Banyan\FilamentMediaLibrary\Access\MediaLibraryGate;
use Banyan\FilamentMediaLibrary\Admin\Support\MediaForms;
use Banyan\FilamentMediaLibrary\Admin\Support\MediaText;
use Banyan\FilamentMediaLibrary\Contracts\UsageResolver;
use Banyan\FilamentMediaLibrary\Governance\MediaExpiryReport;
use Banyan\FilamentMediaLibrary\Governance\MediaExpirySummary;
use Banyan\FilamentMediaLibrary\Governance\MediaGovernanceService;
use Banyan\FilamentMediaLibrary\Models\MediaAsset;
use Banyan\FilamentMediaLibrary\Models\MediaTag;
use Banyan\FilamentMediaLibrary\Rendering\RenditionService;
use Banyan\FilamentMediaLibrary\Rendering\RenditionSpec;
use Banyan\FilamentMediaLibrary\Support\MediaConfig;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Http\UploadedFile;
use Throwable;
use UnitEnum;

/**
 * The library grid.
 *
 * A grid, not a table, because the identifying property of an image is what it
 * looks like. The metadata that a table would lead with — filename, dimensions,
 * size — is what you check *after* you have found the picture, so it sits under
 * the thumbnail rather than instead of it.
 *
 * Two states are surfaced on the card itself rather than behind a filter,
 * because both are governance failures that nobody goes looking for: an asset
 * with no alt text, and an asset whose licence has lapsed.
 */
class MediaLibraryPage extends Page
{
    use MediaText;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-photo';

    protected static ?string $slug = 'media';

    protected string $view = 'media-library::library';

    public string $search = '';

    public ?int $tagId = null;

    public bool $expiredOnly = false;

    public bool $expiringOnly = false;

    /** @var array<int, int> */
    private array $liveUsageCounts = [];

    /** Browsing is an ordinary editorial activity; the write actions are separately gated. */
    public static function canAccess(): bool
    {
        return MediaLibraryGate::allowsBrowse();
    }

    /** An app that builds its own navigation item sets `media-library.navigation.register` to false. */
    public static function shouldRegisterNavigation(): bool
    {
        return MediaConfig::bool('navigation.register', true);
    }

    public static function getNavigationLabel(): string
    {
        return self::text('media.nav.label');
    }

    /** A string or a navigation-group enum, from `media-library.navigation.group`. */
    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return MediaConfig::navigationGroup();
    }

    public static function getNavigationSort(): ?int
    {
        return MediaConfig::int('navigation.sort', 20);
    }

    public function getTitle(): string
    {
        return self::text('media.title');
    }

    public function canManage(): bool
    {
        return MediaLibraryGate::allowsManage();
    }

    /** @return list<MediaAsset> */
    public function assets(): array
    {
        $query = MediaAsset::query()->with('tags')->orderByDesc('id')->limit(120);

        if (trim($this->search) !== '') {
            $term = '%'.trim($this->search).'%';

            $query->where(function ($q) use ($term): void {
                $q->where('filename', 'like', $term)->orWhere('alt_text', 'like', $term);
            });
        }

        if ($this->tagId !== null) {
            $query->whereHas('tags', fn ($q) => $q->where(MediaConfig::table('tags').'.id', $this->tagId));
        }

        if ($this->expiredOnly) {
            $query->whereNotNull('license_expires_at')->where('license_expires_at', '<=', now());
        }

        if ($this->expiringOnly) {
            $query->whereNotNull('license_expires_at')
                ->where('license_expires_at', '>', now())
                ->where('license_expires_at', '<=', now()->addDays($this->expiryWarningDays()));
        }

        $assets = array_values($query->get()->all());
        $assetIds = [];

        foreach ($assets as $asset) {
            $assetIds[] = $asset->id;
        }

        $this->liveUsageCounts = app(UsageResolver::class)->counts($assetIds);

        return $assets;
    }

    public function isFiltered(): bool
    {
        return trim($this->search) !== '' || $this->tagId !== null || $this->expiredOnly || $this->expiringOnly;
    }

    /** @return array<int, string> */
    public function tagOptions(): array
    {
        $options = [];

        foreach (MediaTag::query()->orderBy('name')->get() as $tag) {
            $options[$tag->id] = $tag->name;
        }

        return $options;
    }

    public function resetFilters(): void
    {
        $this->search = '';
        $this->tagId = null;
        $this->expiredOnly = false;
        $this->expiringOnly = false;
    }

    public function showExpiredAssets(): void
    {
        $this->expiredOnly = true;
        $this->expiringOnly = false;
    }

    public function showExpiringAssets(): void
    {
        $this->expiredOnly = false;
        $this->expiringOnly = true;
    }

    public function expirySummary(): MediaExpirySummary
    {
        return app(MediaExpiryReport::class)->summary(warningDays: $this->expiryWarningDays());
    }

    public function expiryWarningDays(): int
    {
        return MediaConfig::expiryWarningDays();
    }

    public function thumbnailUrl(MediaAsset $asset): string
    {
        return app(RenditionService::class)->url($asset, 320, 240, RenditionSpec::FIT_CROP);
    }

    public function assetUrl(MediaAsset $asset): string
    {
        return MediaAssetPage::getUrl(['asset' => $asset->uuid]);
    }

    public function usageCount(MediaAsset $asset): int
    {
        return $this->liveUsageCounts[$asset->id] ?? app(UsageResolver::class)->count($asset->id);
    }

    public function canDelete(MediaAsset $asset): bool
    {
        return $this->canManage() && $this->usageCount($asset) === 0;
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return $this->canManage() ? [$this->uploadAction()] : [];
    }

    public function uploadAction(): Action
    {
        return Action::make('upload')
            ->label(self::text('media.actions.upload'))
            ->icon('heroicon-o-arrow-up-tray')
            ->color('primary')
            ->visible(fn (): bool => $this->canManage())
            ->modalHeading(self::text('media.actions.upload_heading'))
            ->schema(MediaForms::upload())
            ->action(function (array $data): void {
                $actorId = MediaLibraryGate::actorId();
                $file = $data['file'] ?? null;

                if ($actorId === null || ! MediaLibraryGate::allowsManage() || ! $file instanceof UploadedFile) {
                    return;
                }

                try {
                    $asset = app(MediaGovernanceService::class)->upload(
                        (string) file_get_contents($file->getRealPath()),
                        $file->getClientOriginalName(),
                        (string) ($file->getMimeType() ?? 'application/octet-stream'),
                        (string) $data['alt_text'],
                        $actorId,
                        MediaForms::expiry($data['license_expires_at'] ?? null),
                    );

                    if (filled($data['tags'] ?? null)) {
                        app(MediaGovernanceService::class)->syncTags($asset, MediaForms::tags($data['tags']), $actorId);
                    }

                    Notification::make()->success()->title(self::text('media.uploaded'))->send();
                } catch (Throwable $e) {
                    $this->reportFailure(self::text('media.upload_failed'), $e);
                }
            });
    }

    /**
     * Delete, with the usage count named in the confirmation.
     *
     * The grid only offers this for unused assets ({@see canDelete()}); the
     * repository is the real boundary and refuses a referenced asset with
     * `AssetReferenced`, whatever surface asked.
     */
    public function deleteAssetAction(): Action
    {
        return Action::make('deleteAsset')
            ->label(self::text('media.actions.delete'))
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn (): bool => $this->canManage())
            ->modalDescription(function (array $arguments): string {
                $asset = $this->assetFrom($arguments);

                if (! $asset instanceof MediaAsset) {
                    return self::text('media.not_found');
                }

                $count = app(UsageResolver::class)->count($asset->id);

                return $count > 0
                    ? self::text('media.delete_confirm_used', ['name' => $asset->filename, 'count' => $count])
                    : self::text('media.delete_confirm', ['name' => $asset->filename]);
            })
            ->action(function (array $arguments): void {
                $actorId = MediaLibraryGate::actorId();
                $asset = $this->assetFrom($arguments);

                if ($actorId === null || ! MediaLibraryGate::allowsManage() || ! $asset instanceof MediaAsset) {
                    return;
                }

                try {
                    app(MediaGovernanceService::class)->delete($asset, $actorId);

                    Notification::make()->success()->title(self::text('media.deleted'))->send();
                } catch (Throwable $e) {
                    $this->reportFailure(self::text('media.delete_failed'), $e);
                }
            });
    }

    /** @param array<string, mixed> $arguments */
    private function assetFrom(array $arguments): ?MediaAsset
    {
        $id = $arguments['asset'] ?? null;

        return is_numeric($id) ? MediaAsset::query()->find((int) $id) : null;
    }

    private function reportFailure(string $title, Throwable $e): void
    {
        Notification::make()->danger()->title($title)->body($e->getMessage())->send();
    }
}
