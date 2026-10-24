<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Pages\Concerns;

use Banyan\FilamentMediaLibrary\Access\FolderAccess;
use Banyan\FilamentMediaLibrary\Access\MediaLibraryGate;
use Banyan\FilamentMediaLibrary\Enums\FolderAccessMode;
use Banyan\FilamentMediaLibrary\Folders\MediaFolderService;
use Banyan\FilamentMediaLibrary\Models\MediaFolder;
use Banyan\FilamentMediaLibrary\Models\MediaFolderMember;
use Banyan\FilamentMediaLibrary\Pages\MediaLibraryPage;
use Banyan\FilamentMediaLibrary\Support\MediaConfig;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * The "Access…" action: mode, password and members of the open folder.
 *
 * Offered to the folder's owner and to `media.manage-all` only
 * ({@see FolderAccess::canAdminister()}), and checked again inside the handler.
 * The form never carries the current password or its hash: the password field
 * is always empty, and an empty one on an already protected folder means "keep it".
 *
 * Members are users of the host app: the model comes from
 * `auth.providers.users.model`, and `media-library.folders.member_label` names
 * the attribute shown (default `name`, then `email`).
 *
 * @phpstan-require-extends MediaLibraryPage
 */
trait ManagesFolderAccess
{
    public function accessFolderAction(): Action
    {
        return Action::make('accessFolder')
            ->label(self::text('media.access.action'))
            ->icon('heroicon-o-lock-closed')
            ->color('gray')
            ->modalHeading(self::text('media.access.heading'))
            ->visible(fn (): bool => $this->mayAdminister())
            ->fillForm(fn (): array => [
                'mode' => $this->currentFolder()?->accessMode()->value ?? FolderAccessMode::Inherit->value,
                'members' => $this->currentFolder() instanceof MediaFolder ? $this->memberItems($this->currentFolder()) : [],
            ])
            ->schema(fn (): array => $this->accessSchema())
            ->action(function (array $data): void {
                $actorId = MediaLibraryGate::actorId();
                $folder = $this->currentFolder();
                $mode = FolderAccessMode::tryFrom(is_string($data['mode'] ?? null) ? $data['mode'] : '');

                if ($actorId === null || ! $folder instanceof MediaFolder || ! $mode instanceof FolderAccessMode || ! $this->mayAdminister()) {
                    Notification::make()->danger()->title(self::text('media.access.refused'))->send();

                    return;
                }

                // Restricting a folder whose files are still used on pages needs the same confirmation as moving them in.
                if ($mode->isRestricted() && ! $folder->isRestricted() && $this->usedAmong($folder->subtreeAssets()) > 0 && ($data['confirm_usage'] ?? false) !== true) {
                    Notification::make()->danger()->title(self::text('media.access.confirm_usage'))->send();

                    return;
                }

                $this->attempt(self::text('media.access.saved'), self::text('media.access.save_failed'), function () use ($folder, $mode, $data, $actorId): void {
                    $service = app(MediaFolderService::class);
                    $service->setAccess($folder, $mode, is_string($data['password'] ?? null) ? $data['password'] : null, $actorId);

                    if ($mode === FolderAccessMode::Members) {
                        $this->syncMembers($folder, is_array($data['members'] ?? null) ? $data['members'] : [], $actorId);
                    }
                });
            });
    }

    private function mayAdminister(): bool
    {
        $folder = $this->currentFolder();

        return $this->foldersEnabled() && $folder instanceof MediaFolder && app(FolderAccess::class)->canAdminister($folder);
    }

    /** @return list<Component> */
    private function accessSchema(): array
    {
        $isMode = static fn (Get $get, FolderAccessMode $mode): bool => $get('mode') === $mode->value;
        $hasPassword = $this->currentFolder()?->accessMode() === FolderAccessMode::Password;

        return [
            Select::make('mode')
                ->label(self::text('media.access.mode_label'))
                ->helperText(self::text('media.access.mode_help'))
                ->options(FolderAccessMode::options())
                ->required()
                ->live()
                ->selectablePlaceholder(false),
            TextInput::make('password')
                ->label(self::text('media.access.password'))
                ->helperText(self::text('media.access.password_help'))
                ->password()
                ->revealable()
                ->autocomplete('new-password')
                ->minLength(MediaFolderService::PASSWORD_MIN)
                ->maxLength(255)
                ->confirmed()
                ->required(fn (Get $get): bool => $isMode($get, FolderAccessMode::Password) && ! $hasPassword)
                ->visible(fn (Get $get): bool => $isMode($get, FolderAccessMode::Password)),
            TextInput::make('password_confirmation')
                ->label(self::text('media.access.password_confirmation'))
                ->password()
                ->revealable()
                ->autocomplete('new-password')
                ->maxLength(255)
                ->dehydrated(false)
                ->visible(fn (Get $get): bool => $isMode($get, FolderAccessMode::Password)),
            Repeater::make('members')
                ->label(self::text('media.access.members'))
                ->helperText(self::text('media.access.members_help'))
                ->addActionLabel(self::text('media.access.member_add'))
                ->visible(fn (Get $get): bool => $isMode($get, FolderAccessMode::Members))
                ->schema([
                    Select::make('user_id')
                        ->label(self::text('media.access.member_user'))
                        ->searchable()
                        ->required()
                        ->getSearchResultsUsing(fn (string $search): array => $this->memberOptions($search))
                        ->getOptionLabelUsing(fn (mixed $value): ?string => $this->memberLabel($value)),
                    Select::make('role')
                        ->label(self::text('media.access.member_role'))
                        ->options([
                            'view' => self::text('media.access.role.view'),
                            'manage' => self::text('media.access.role.manage'),
                        ])
                        ->default('view')
                        ->required()
                        ->selectablePlaceholder(false),
                ]),
            $this->confirmUsageField(fn (Get $get): int => ($mode = FolderAccessMode::tryFrom(is_string($get('mode')) ? $get('mode') : '')) instanceof FolderAccessMode && $mode->isRestricted() && ! ($this->currentFolder()?->isRestricted() ?? true)
                ? $this->usedAmong($this->currentFolder()?->subtreeAssets() ?? [])
                : 0),
        ];
    }

    /** @return list<array{user_id: int, role: string}> */
    private function memberItems(MediaFolder $folder): array
    {
        return array_values(array_map(
            static fn (MediaFolderMember $member): array => ['user_id' => $member->user_id, 'role' => $member->role],
            $folder->members()->get()->all(),
        ));
    }

    /**
     * Make the folder's members exactly the form's list: add, re-role and remove.
     *
     * @param  array<array-key, mixed>  $items
     */
    private function syncMembers(MediaFolder $folder, array $items, string $actorId): void
    {
        $service = app(MediaFolderService::class);
        $class = $this->userModel();
        $wanted = [];

        foreach ($items as $item) {
            if (is_array($item) && is_numeric($item['user_id'] ?? null) && ($item['role'] ?? null) === 'manage') {
                $wanted[(int) $item['user_id']] = 'manage';
            } elseif (is_array($item) && is_numeric($item['user_id'] ?? null) && ($item['role'] ?? null) === 'view') {
                $wanted[(int) $item['user_id']] = 'view';
            }
        }

        foreach ($folder->members()->get() as $existing) {
            if (! isset($wanted[$existing->user_id])) {
                $service->removeMember($folder, $existing->user_id, $actorId);
            }
        }

        foreach ($wanted as $userId => $role) {
            // Only people who exist can be members.
            if ($class !== null && ! $class::query()->whereKey($userId)->exists()) {
                continue;
            }

            $service->addMember($folder, $userId, $role, $actorId);
        }
    }

    /** @return class-string<Model>|null */
    private function userModel(): ?string
    {
        $model = config('auth.providers.users.model');

        return is_string($model) && is_subclass_of($model, Model::class) ? $model : null;
    }

    /** The configured attribute if the users table has it, otherwise `email`. */
    private function memberColumn(Model $user): string
    {
        $column = MediaConfig::string('folders.member_label', 'name');

        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column) === 1 && Schema::hasColumn($user->getTable(), $column) ? $column : 'email';
    }

    /** @return array<int, string> */
    private function memberOptions(string $search): array
    {
        $class = $this->userModel();

        if ($class === null) {
            return [];
        }

        $user = new $class;
        $column = $this->memberColumn($user);
        // A `%` or `_` typed into the search only widens the match among a few users; no escaping is needed.
        $like = '%'.trim($search).'%';
        $options = [];

        foreach ($class::query()->where($column, 'like', $like)->orderBy($column)->limit(50)->get() as $found) {
            $options[self::intOf($found->getKey())] = self::stringOf($found->getAttribute($column));
        }

        return $options;
    }

    private static function intOf(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function stringOf(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private function memberLabel(mixed $id): ?string
    {
        $class = $this->userModel();

        if ($class === null || ! is_numeric($id)) {
            return null;
        }

        $user = $class::query()->find((int) $id);

        // A user that does not exist has no label, which is also how the select refuses it.
        return $user instanceof Model ? self::stringOf($user->getAttribute($this->memberColumn($user))) : null;
    }
}
