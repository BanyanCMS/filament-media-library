<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Admin;

use BackedEnum;
use Banyan\FilamentMediaLibrary\Access\FolderAccess;
use Banyan\FilamentMediaLibrary\Access\MediaLibraryGate;
use Banyan\FilamentMediaLibrary\Admin\Concerns\BrowsesFolders;
use Banyan\FilamentMediaLibrary\Admin\Concerns\GuardsRestrictedMoves;
use Banyan\FilamentMediaLibrary\Admin\Concerns\ManagesFolderAccess;
use Banyan\FilamentMediaLibrary\Admin\Concerns\ManagesFolders;
use Banyan\FilamentMediaLibrary\Admin\Concerns\RunsMediaActions;
use Banyan\FilamentMediaLibrary\Admin\Support\MediaForms;
use Banyan\FilamentMediaLibrary\Admin\Support\MediaText;
use Banyan\FilamentMediaLibrary\Contracts\UsageResolver;
use Banyan\FilamentMediaLibrary\Governance\MediaExpiryReport;
use Banyan\FilamentMediaLibrary\Governance\MediaExpirySummary;
use Banyan\FilamentMediaLibrary\Governance\MediaGovernanceService;
use Banyan\FilamentMediaLibrary\MediaLibraryPlugin;
use Banyan\FilamentMediaLibrary\Models\MediaAsset;
use Banyan\FilamentMediaLibrary\Models\MediaTag;
use Banyan\FilamentMediaLibrary\Rendering\PdfThumbnailService;
use Banyan\FilamentMediaLibrary\Rendering\RenditionService;
use Banyan\FilamentMediaLibrary\Rendering\RenditionSpec;
use Banyan\FilamentMediaLibrary\Support\MediaConfig;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use UnitEnum;

/**
 * The library grid.
 *
 * A grid, not a table, because the identifying property of an image is what it
 * looks like. The metadata that a table would lead with, filename, dimensions,
 * size, is what you check *after* you have found the picture, so it sits under
 * the thumbnail rather than instead of it.
 *
 * Two states are surfaced on the card itself rather than behind a filter,
 * because both are governance failures that nobody goes looking for: an asset
 * with no alt text, and an asset whose licence has lapsed.
 */
class MediaLibraryPage extends Page
{
    use BrowsesFolders;
    use GuardsRestrictedMoves;
    use ManagesFolderAccess;
    use ManagesFolders;
    use MediaText;
    use RunsMediaActions;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-photo';

    protected static ?string $slug = 'media';

    protected string $view = 'media-library::library';

    public string $search = '';

    public ?int $tagId = null;

    public bool $expiredOnly = false;

    public bool $expiringOnly = false;

    /** How many cards the grid shows; "Load more" raises it a page at a time. */
    public int $shown = self::PAGE_SIZE;

    public const PAGE_SIZE = 120;

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

    /** May the signed-in user upload, move and delete in the open folder (the root when none is open)? */
    public function canManage(): bool
    {
        return app(FolderAccess::class)->canManage($this->currentFolder());
    }

    /** May the signed-in user change this asset? Decided by the folder it is in, not by the folder that is open. */
    public function canManageAsset(MediaAsset $asset): bool
    {
        return app(FolderAccess::class)->canManage($asset);
    }

    /** @return list<MediaAsset> */
    public function assets(): array
    {
        $query = $this->filtered()->with('tags')->orderByDesc('id')->limit(max(self::PAGE_SIZE, $this->shown));

        $assets = array_values($query->get()->all());
        $assetIds = [];

        foreach ($assets as $asset) {
            $assetIds[] = $asset->id;
        }

        $this->liveUsageCounts = app(UsageResolver::class)->counts($assetIds);

        return $assets;
    }

    /** Every asset the current filters match, not just the ones on screen. */
    public function totalAssets(): int
    {
        return $this->filtered()->count();
    }

    public function loadMore(): void
    {
        $this->shown += self::PAGE_SIZE;
    }

    /** A new filter starts from the first page again. */
    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'tagId', 'expiredOnly', 'expiringOnly', 'wholeLibrary'], true)) {
            $this->shown = self::PAGE_SIZE;
        }
    }

    /** @return Builder<MediaAsset> */
    private function filtered(): Builder
    {
        // The model's own scopes: the grid filters exactly as host code queries. visibleTo() comes
        // first and applies to every mode below (browsing, searching, the whole library): an asset
        // the user may not see is not in the query, so it cannot be in the page, a count or a payload.
        $query = MediaAsset::query()->visibleTo()->search($this->search);

        // Browsing shows what is directly in the open folder. A search or filter
        // reaches into its sub-folders too (or the whole library, when asked): an
        // editor hunting for a file does not know which folder it was put in.
        if ($this->foldersEnabled()) {
            if (! $this->isFiltered()) {
                $query->inFolder($this->currentFolder());
            } elseif (! $this->wholeLibrary) {
                $query->inFolder($this->currentFolder(), true);
            }
        }

        if ($this->tagId !== null) {
            $query->whereHas('tags', fn ($q) => $q->where(MediaConfig::table('tags').'.id', $this->tagId));
        }

        if ($this->expiredOnly) {
            $query->expired();
        }

        if ($this->expiringOnly) {
            $query->expiringWithin($this->expiryWarningDays());
        }

        return $query;
    }

    public function isFiltered(): bool
    {
        return trim($this->search) !== '' || $this->tagId !== null || $this->expiredOnly || $this->expiringOnly;
    }

    /** @return array<int, string> */
    public function tagOptions(): array
    {
        $options = [];

        // Only tags on assets this user may see: a tag name can give away what is in a hidden folder.
        $visible = MediaTag::query()->whereHas('assets', fn ($q) => $q->visibleTo());

        foreach ($visible->orderBy('name')->get() as $tag) {
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
        $this->wholeLibrary = false;
        $this->shown = self::PAGE_SIZE;
    }

    public function showExpiredAssets(): void
    {
        $this->expiredOnly = true;
        $this->expiringOnly = false;
        $this->shown = self::PAGE_SIZE;
    }

    public function showExpiringAssets(): void
    {
        $this->expiredOnly = false;
        $this->expiringOnly = true;
        $this->shown = self::PAGE_SIZE;
    }

    public function expirySummary(): MediaExpirySummary
    {
        // Counted over the assets this user may see: a banner total must not give away restricted files.
        return app(MediaExpiryReport::class)->summary(warningDays: $this->expiryWarningDays(), visibleTo: auth()->user());
    }

    public function expiryWarningDays(): int
    {
        return MediaConfig::expiryWarningDays();
    }

    public function thumbnailUrl(MediaAsset $asset): string
    {
        return app(RenditionService::class)->url($asset, 320, 240, RenditionSpec::FIT_CROP);
    }

    /** The first-page thumbnail of a PDF, when this host can render one; otherwise the card shows the kind icon. */
    public function pdfThumbnailUrl(MediaAsset $asset): ?string
    {
        return app(PdfThumbnailService::class)->url($asset);
    }

    public function assetUrl(MediaAsset $asset): string
    {
        return MediaLibraryPlugin::assetPageClass()::getUrl(['asset' => $asset->uuid]);
    }

    public function usageCount(MediaAsset $asset): int
    {
        return $this->liveUsageCounts[$asset->id] ?? app(UsageResolver::class)->count($asset->id);
    }

    public function canDelete(MediaAsset $asset): bool
    {
        return $this->canManageAsset($asset) && $this->usageCount($asset) === 0;
    }

    /** @return array<int, Action|ActionGroup> */
    protected function getHeaderActions(): array
    {
        return $this->canManage() ? [$this->uploadAction(), ...$this->folderHeaderActions()] : [];
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

                // Uploads go into the open folder, so that is the folder the user must be able to manage.
                if ($actorId === null || ! $this->canManage() || ! $file instanceof UploadedFile) {
                    return;
                }

                $this->attempt(self::text('media.uploaded'), self::text('media.upload_failed'), function () use ($file, $data, $actorId): void {
                    MediaForms::storeUpload($file, $data, $actorId, $this->currentFolder());
                });
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

                return $this->deleteConfirmation($asset instanceof MediaAsset && $this->canManageAsset($asset) ? $asset : null);
            })
            ->action(function (array $arguments): void {
                $actorId = MediaLibraryGate::actorId();
                $asset = $this->assetFrom($arguments);

                if ($actorId === null || ! $asset instanceof MediaAsset || ! $this->canManageAsset($asset)) {
                    return;
                }

                $this->attempt(self::text('media.deleted'), self::text('media.delete_failed'), function () use ($asset, $actorId): void {
                    app(MediaGovernanceService::class)->delete($asset, $actorId);
                });
            });
    }

    /** @param array<string, mixed> $arguments */
    private function assetFrom(array $arguments): ?MediaAsset
    {
        $id = $arguments['asset'] ?? null;

        // visibleTo(): an id for an asset this user may not see finds nothing, exactly like one that does not exist.
        return is_numeric($id) ? MediaAsset::query()->visibleTo()->find((int) $id) : null;
    }
}
