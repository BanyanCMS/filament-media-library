<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Admin\Concerns;

use Banyan\FilamentMediaLibrary\Access\FolderAccess;
use Banyan\FilamentMediaLibrary\Access\MediaLibraryGate;
use Banyan\FilamentMediaLibrary\Admin\MediaLibraryPage;
use Banyan\FilamentMediaLibrary\Admin\Support\FolderChoices;
use Banyan\FilamentMediaLibrary\Folders\MediaFolderService;
use Banyan\FilamentMediaLibrary\Models\MediaAsset;
use Banyan\FilamentMediaLibrary\Models\MediaFolder;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;

/**
 * The folder write actions of the library page.
 *
 * Each action is hidden from people who may not manage the folder it acts on,
 * and asks {@see FolderAccess} again inside its handler: `visible()` only hides
 * a button, and a Livewire action can be mounted without one. A destination
 * folder is checked the same way, and a destination the user may not use is
 * refused with the same message as one that does not exist. Every write goes
 * through {@see MediaFolderService}, which owns the rules and the events.
 *
 * @phpstan-require-extends MediaLibraryPage
 */
trait ManagesFolders
{
    public function canManageFolders(): bool
    {
        return $this->foldersEnabled() && $this->canManage();
    }

    /** @return list<Action|ActionGroup> */
    protected function folderHeaderActions(): array
    {
        if (! $this->canManageFolders()) {
            return [];
        }

        return [
            $this->newFolderAction(),
            ActionGroup::make([$this->accessFolderAction(), $this->renameFolderAction(), $this->moveFolderAction(), $this->deleteFolderAction()])
                ->label(self::text('media.folders.group'))
                ->icon('heroicon-m-ellipsis-vertical')
                ->color('gray')
                ->button()
                ->visible(fn (): bool => $this->currentFolder() instanceof MediaFolder),
        ];
    }

    public function newFolderAction(): Action
    {
        return Action::make('newFolder')
            ->label(self::text('media.folders.new'))
            ->icon('heroicon-o-folder-plus')
            ->color('gray')
            ->modalHeading(self::text('media.folders.new_heading'))
            ->visible(fn (): bool => $this->canManageFolders())
            ->schema([$this->nameField()])
            ->action(function (array $data): void {
                $actorId = $this->folderActor();

                if ($actorId === null) {
                    return;
                }

                $this->attempt(self::text('media.folders.created'), self::text('media.folders.create_failed'), function () use ($data, $actorId): void {
                    $parent = $this->currentFolder();
                    $this->assertDestinationAllowed($parent);
                    app(MediaFolderService::class)->create($parent, (string) ($data['name'] ?? ''), $actorId, $parent instanceof MediaFolder ? null : $this->currentSiteId());
                });
            });
    }

    public function renameFolderAction(): Action
    {
        return Action::make('renameFolder')
            ->label(self::text('media.folders.rename'))
            ->icon('heroicon-o-pencil-square')
            ->color('gray')
            ->modalHeading(self::text('media.folders.rename_heading'))
            ->visible(fn (): bool => $this->canManageFolders() && $this->currentFolder() instanceof MediaFolder)
            ->fillForm(fn (): array => ['name' => $this->currentFolder()?->name])
            ->schema([$this->nameField()])
            ->action(function (array $data): void {
                $actorId = $this->folderActor();
                $folder = $this->currentFolder();

                if ($actorId === null || ! $folder instanceof MediaFolder || ! app(FolderAccess::class)->canManage($folder)) {
                    return;
                }

                $this->attempt(self::text('media.folders.renamed'), self::text('media.folders.rename_failed'), function () use ($folder, $data, $actorId): void {
                    app(MediaFolderService::class)->rename($folder, (string) ($data['name'] ?? ''), $actorId);
                });
            });
    }

    public function moveFolderAction(): Action
    {
        return Action::make('moveFolder')
            ->label(self::text('media.folders.move'))
            ->icon('heroicon-o-arrow-right-circle')
            ->color('gray')
            ->modalHeading(self::text('media.folders.move_heading'))
            ->visible(fn (): bool => $this->canManageFolders() && $this->currentFolder() instanceof MediaFolder)
            ->fillForm(fn (): array => ['destination' => $this->currentFolder()?->parent?->uuid])
            ->schema(fn (): array => [
                $this->destinationField($this->currentFolder()),
                $this->confirmUsageField(fn (Get $get): int => $this->usedFileCount($this->safeFolder($get('destination')), $this->currentFolder()?->subtreeAssets())),
            ])
            ->action(function (array $data): void {
                $actorId = $this->folderActor();
                $folder = $this->currentFolder();

                if ($actorId === null || ! $folder instanceof MediaFolder || ! app(FolderAccess::class)->canManage($folder)) {
                    return;
                }

                $this->attempt(self::text('media.folders.moved'), self::text('media.folders.move_failed'), function () use ($folder, $data, $actorId): bool {
                    $destination = $this->folderFrom($data['destination'] ?? null);

                    if (! $this->usageConfirmed($destination, $folder->subtreeAssets(), $data)) {
                        return false;
                    }

                    app(MediaFolderService::class)->move($folder, $destination, $actorId);

                    return true;
                });
            });
    }

    /**
     * Delete the open folder. A folder with contents is refused unless the editor
     * ticks "move contents to the parent first", so nothing is lost by accident.
     */
    public function deleteFolderAction(): Action
    {
        return Action::make('deleteFolder')
            ->label(self::text('media.folders.delete'))
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(self::text('media.folders.delete_heading'))
            ->modalDescription(function (): string {
                $folder = $this->currentFolder();

                if (! $folder instanceof MediaFolder) {
                    return self::text('media.folders.not_found');
                }

                return $folder->children()->exists() || $folder->assets()->exists()
                    ? self::text('media.folders.delete_not_empty')
                    : self::text('media.folders.delete_confirm');
            })
            ->visible(fn (): bool => $this->canManageFolders() && $this->currentFolder() instanceof MediaFolder)
            ->schema([
                Toggle::make('move_contents')
                    ->label(self::text('media.folders.move_contents'))
                    ->helperText(self::text('media.folders.move_contents_help')),
            ])
            ->action(function (array $data): void {
                $actorId = $this->folderActor();
                $folder = $this->currentFolder();

                if ($actorId === null || ! $folder instanceof MediaFolder || ! app(FolderAccess::class)->canManage($folder)) {
                    return;
                }

                $parent = $folder->parent?->uuid;

                $this->attempt(self::text('media.folders.deleted'), self::text('media.folders.delete_failed'), function () use ($folder, $data, $actorId, $parent): void {
                    $service = app(MediaFolderService::class);

                    ($data['move_contents'] ?? false) === true
                        ? $service->deleteMovingContentsToParent($folder, $actorId)
                        : $service->delete($folder, $actorId);

                    $this->openFolder($parent);
                });
            });
    }

    /** Move the ticked assets into a folder. Shown in the grid's selection bar, not the header. */
    public function moveSelectedAction(): Action
    {
        return Action::make('moveSelected')
            ->label(self::text('media.folders.move_selected'))
            ->icon('heroicon-o-folder-arrow-down')
            ->color('gray')
            ->modalHeading(self::text('media.folders.move_selected_heading'))
            ->visible(fn (): bool => $this->canManageFolders())
            ->schema(fn (): array => [
                $this->destinationField(null),
                $this->confirmUsageField(fn (Get $get): int => $this->usedFileCount($this->safeFolder($get('destination')), $this->selectedAssets())),
            ])
            ->action(function (array $data): void {
                $actorId = $this->folderActor();

                if ($actorId === null) {
                    return;
                }

                $this->attempt(
                    static fn (mixed $moved): string => self::choice('media.folders.assets_moved', is_int($moved) ? $moved : 0),
                    self::text('media.folders.move_failed'),
                    function () use ($data, $actorId): int|false {
                        $destination = $this->folderFrom($data['destination'] ?? null);
                        $assets = $this->selectedAssets();

                        if (! $this->usageConfirmed($destination, $assets, $data)) {
                            return false;
                        }

                        $moved = app(MediaFolderService::class)->moveAssets($assets, $destination, $actorId);

                        $this->selected = [];

                        return $moved;
                    },
                );
            });
    }

    /**
     * The ticked assets this user may move: ones they can see and manage. Any other
     * id in the list (a stale tick, or one a client invented) is dropped, not reported.
     *
     * @return list<MediaAsset>
     */
    private function selectedAssets(): array
    {
        $ids = array_values(array_filter(array_map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, $this->selected)));
        $access = app(FolderAccess::class);

        return array_values(array_filter(
            MediaAsset::query()->visibleTo()->whereIn('id', $ids)->get()->all(),
            static fn (MediaAsset $asset): bool => $access->canManage($asset),
        ));
    }

    private function nameField(): TextInput
    {
        return TextInput::make('name')
            ->label(self::text('media.folders.name'))
            ->required()
            ->maxLength(120);
    }

    /** A folder picker; leaving it empty means the library root. */
    private function destinationField(?MediaFolder $moving): Select
    {
        return Select::make('destination')
            ->label(self::text('media.folders.destination'))
            ->placeholder(self::text('media.folders.root'))
            ->searchable()
            ->live()
            ->options(fn (): array => FolderChoices::options($moving));
    }

    /** The acting user, or null when folders are off or the gate refuses. */
    private function folderActor(): ?string
    {
        return $this->canManageFolders() ? MediaLibraryGate::actorId() : null;
    }
}
