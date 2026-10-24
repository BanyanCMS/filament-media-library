<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Pages\Concerns;

use Banyan\FilamentMediaLibrary\Access\FolderAccess;
use Banyan\FilamentMediaLibrary\Enums\FolderAccessMode;
use Banyan\FilamentMediaLibrary\Folders\FolderChoices;
use Banyan\FilamentMediaLibrary\Folders\FolderNode;
use Banyan\FilamentMediaLibrary\Models\MediaAsset;
use Banyan\FilamentMediaLibrary\Models\MediaFolder;
use Banyan\FilamentMediaLibrary\Pages\MediaLibraryPage;
use Banyan\FilamentMediaLibrary\Support\MediaConfig;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Livewire\Attributes\Url;

/**
 * The folder state of the library page: which folder is open, the sidebar tree,
 * the breadcrumbs and the tiles of sub-folders.
 *
 * The open folder is its uuid in the query string (`?folder=…`), never the
 * autoincrement id, so a folder is linkable without exposing an enumerable key.
 * With `media-library.folders.enabled` off every method answers as if the
 * library were flat and the root were the only folder.
 *
 * Nothing here decides access itself: every list is read through
 * {@see FolderAccess}, so a folder the user may not see is never loaded, and the
 * uuid in the URL of one they may not see opens the root. A password folder is
 * listed (so there is something to unlock) while its contents are not.
 *
 * @phpstan-require-extends MediaLibraryPage
 */
trait BrowsesFolders
{
    #[Url(as: 'folder')]
    public ?string $currentFolder = null;

    /** Search the whole library instead of the open folder and below. */
    public bool $wholeLibrary = false;

    /** @var list<string> uuids of the branches the editor opened in the sidebar */
    public array $expanded = [];

    /** @var list<int|string> asset ids ticked for a bulk move */
    public array $selected = [];

    /** @var (Closure(): ?int)|null */
    private static ?Closure $siteResolver = null;

    /**
     * Tell the page which site the editor is working on, so that site's
     * top-level folders are listed first. A standalone install never calls this.
     *
     * @param  (Closure(): ?int)|null  $resolver
     */
    public static function currentSiteUsing(?Closure $resolver): void
    {
        self::$siteResolver = $resolver;
    }

    public function currentSiteId(): ?int
    {
        return self::$siteResolver === null ? null : (self::$siteResolver)();
    }

    public function foldersEnabled(): bool
    {
        return MediaConfig::foldersEnabled();
    }

    /** @var array{0: string, 1: MediaFolder|null}|null the folder resolved for this request, keyed by the uuid it was resolved from */
    private ?array $resolvedFolder = null;

    /**
     * The open folder; null for the root, when the uuid in the URL matches
     * nothing, or when the folder is not one this user may see.
     */
    public function currentFolder(): ?MediaFolder
    {
        if (! $this->foldersEnabled() || blank($this->currentFolder)) {
            return null;
        }

        $uuid = (string) $this->currentFolder;

        if ($this->resolvedFolder === null || $this->resolvedFolder[0] !== $uuid) {
            $folder = MediaFolder::findByUuid($uuid);
            $this->resolvedFolder = [$uuid, $folder instanceof MediaFolder && app(FolderAccess::class)->isListed($folder) ? $folder : null];
        }

        return $this->resolvedFolder[1];
    }

    /**
     * Before the component is serialised: forget a folder uuid this user may not see.
     * It arrives from the URL or a forged update, and would otherwise be written
     * straight back into the page's snapshot, which is part of the response.
     */
    public function dehydrateBrowsesFolders(): void
    {
        if (filled($this->currentFolder) && ! $this->currentFolder() instanceof MediaFolder) {
            $this->currentFolder = null;
            $this->resolvedFolder = null;
        }

        $access = app(FolderAccess::class);

        $this->expanded = array_values(array_filter(
            $this->expanded,
            static fn (string $uuid): bool => ($folder = MediaFolder::findByUuid($uuid)) instanceof MediaFolder && $access->isListed($folder),
        ));
    }

    /** The open folder when it is password-protected and not yet unlocked: the page shows a form instead of its contents. */
    public function lockedFolder(): ?MediaFolder
    {
        $folder = $this->currentFolder();

        return $folder instanceof MediaFolder && app(FolderAccess::class)->isLocked($folder) ? $folder : null;
    }

    /** Open a folder (null: the root). An unknown folder, or one this user may not see, opens the root. */
    public function openFolder(?string $uuid = null): void
    {
        $folder = blank($uuid) || ! $this->foldersEnabled() ? null : MediaFolder::findByUuid($uuid);
        $folder = $folder instanceof MediaFolder && app(FolderAccess::class)->isListed($folder) ? $folder : null;
        $this->resolvedFolder = null;

        $this->currentFolder = $folder?->uuid;
        $this->selected = [];
        $this->shown = self::PAGE_SIZE;

        if ($folder instanceof MediaFolder) {
            $this->expanded = array_values(array_unique([...$this->expanded, $folder->uuid]));
        }
    }

    public function toggleFolder(string $uuid): void
    {
        // Only branches the user can see are remembered, so the list never holds a uuid they should not have.
        $folder = MediaFolder::findByUuid($uuid);

        if (! $folder instanceof MediaFolder || ! app(FolderAccess::class)->isListed($folder)) {
            return;
        }

        $this->expanded = in_array($uuid, $this->expanded, true)
            ? array_values(array_diff($this->expanded, [$uuid]))
            : [...$this->expanded, $uuid];
    }

    /** @return list<FolderNode> */
    public function folderTree(): array
    {
        return FolderChoices::tree($this->currentFolder(), $this->expanded, $this->currentSiteId());
    }

    /**
     * The folders directly inside the open folder that this user may see, with
     * how much each holds *of what they may see*: a count never includes a hidden
     * file or folder, and a locked folder carries none.
     *
     * @return list<MediaFolder>
     */
    public function subfolders(): array
    {
        $folder = $this->currentFolder();
        $query = MediaFolder::query()->visibleTo()->orderBy('name');

        $folder instanceof MediaFolder ? $query->where('parent_id', $folder->id) : $query->whereNull('parent_id');

        $folders = array_values($query->get()->all());
        $ids = array_map(static fn (MediaFolder $f): int => $f->id, $folders);

        if ($ids !== []) {
            $children = MediaFolder::query()->visibleTo()->whereIn('parent_id', $ids)
                ->selectRaw('parent_id, count(*) as total')->groupBy('parent_id')->pluck('total', 'parent_id');
            $assets = MediaAsset::query()->visibleTo()->whereIn('folder_id', $ids)
                ->selectRaw('folder_id, count(*) as total')->groupBy('folder_id')->pluck('total', 'folder_id');

            foreach ($folders as $item) {
                $item->setAttribute('children_count', self::countOf($children[$item->id] ?? 0));
                $item->setAttribute('assets_count', self::countOf($assets[$item->id] ?? 0));
            }
        }

        return $folder instanceof MediaFolder ? $folders : FolderChoices::siteFirst($folders, $this->currentSiteId());
    }

    private static function countOf(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /** Password-protected and not unlocked by this user. */
    public function isFolderLocked(MediaFolder $folder): bool
    {
        return app(FolderAccess::class)->isLocked($folder);
    }

    /** Members-only (the user is one of the members, or they would not see it). */
    public function isFolderMembersOnly(MediaFolder $folder): bool
    {
        return app(FolderAccess::class)->effectiveMode($folder) === FolderAccessMode::Members;
    }

    /** Unlock the open folder with its password. The form's password is never stored, echoed or put in an event. */
    public function unlockFolderAction(): Action
    {
        return Action::make('unlockFolder')
            ->label(self::text('media.access.unlock'))
            ->icon('heroicon-o-lock-open')
            ->modalHeading(self::text('media.access.unlock_heading'))
            ->visible(fn (): bool => $this->lockedFolder() instanceof MediaFolder)
            ->schema([
                TextInput::make('password')
                    ->label(self::text('media.access.password_field'))
                    ->password()
                    ->required()
                    ->maxLength(255),
            ])
            ->action(function (array $data): void {
                $folder = $this->lockedFolder();
                $access = app(FolderAccess::class);

                if (! $folder instanceof MediaFolder) {
                    return;
                }

                if ($access->isThrottled($folder)) {
                    $this->throttledNotice($folder);

                    return;
                }

                if ($access->unlock($folder, is_string($data['password'] ?? null) ? $data['password'] : '')) {
                    Notification::make()->success()->title(self::text('media.access.unlocked'))->send();

                    return;
                }

                $access->isThrottled($folder)
                    ? $this->throttledNotice($folder)
                    : Notification::make()->danger()->title(self::text('media.access.wrong_password'))->send();
            });
    }

    private function throttledNotice(MediaFolder $folder): void
    {
        Notification::make()->danger()
            ->title(self::text('media.access.throttled', ['minutes' => max(1, (int) ceil(app(FolderAccess::class)->retryAfter($folder) / 60))]))
            ->send();
    }

    /** @return list<MediaFolder> outermost first, ending at the open folder */
    public function breadcrumbs(): array
    {
        return $this->currentFolder()?->breadcrumbs() ?? [];
    }

    /** Sub-folders plus assets, for the tile's "12 items". Needs the counts loaded by {@see subfolders()}. */
    public function itemCount(MediaFolder $folder): int
    {
        return (int) $folder->assets_count + (int) $folder->children_count;
    }
}
