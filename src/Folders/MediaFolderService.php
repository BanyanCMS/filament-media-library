<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Folders;

use Banyan\FilamentMediaLibrary\Access\FolderAccess;
use Banyan\FilamentMediaLibrary\Enums\FolderAccessMode;
use Banyan\FilamentMediaLibrary\Events\AssetMoved;
use Banyan\FilamentMediaLibrary\Events\FolderAccessChanged;
use Banyan\FilamentMediaLibrary\Events\FolderCreated;
use Banyan\FilamentMediaLibrary\Events\FolderDeleted;
use Banyan\FilamentMediaLibrary\Events\FolderMembersChanged;
use Banyan\FilamentMediaLibrary\Events\FolderMoved;
use Banyan\FilamentMediaLibrary\Events\FolderRenamed;
use Banyan\FilamentMediaLibrary\Exceptions\FolderCycle;
use Banyan\FilamentMediaLibrary\Exceptions\FolderNameTaken;
use Banyan\FilamentMediaLibrary\Exceptions\FolderNotEmpty;
use Banyan\FilamentMediaLibrary\Exceptions\FolderTooDeep;
use Banyan\FilamentMediaLibrary\Exceptions\InvalidFolderAccess;
use Banyan\FilamentMediaLibrary\Exceptions\InvalidFolderName;
use Banyan\FilamentMediaLibrary\Exceptions\MediaException;
use Banyan\FilamentMediaLibrary\Governance\MediaGovernanceService;
use Banyan\FilamentMediaLibrary\Models\MediaAsset;
use Banyan\FilamentMediaLibrary\Models\MediaFolder;
use Banyan\FilamentMediaLibrary\Models\MediaFolderMember;
use Banyan\FilamentMediaLibrary\Support\MediaConfig;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Every folder write goes through here.
 *
 * Like {@see MediaGovernanceService}
 * each change is one transaction that dispatches its event before it commits,
 * so an audit listener that refuses the entry undoes the change. Events carry
 * ids, uuids and depth, never a folder name: "Jane Doe HR file" is personal data.
 *
 * This class also keeps the two denormalised columns honest. `path` (the ids
 * from the top-level folder down, "/12/45/") and `depth` are rewritten for the
 * whole subtree whenever a folder moves; nothing else may change `parent_id`.
 * Names are unique per parent *case-insensitively*, which a database unique
 * index cannot promise on every engine (and cannot promise at all for the root,
 * where NULL parents never collide), so it is checked here as well.
 *
 * **This service is not gated.** Like {@see MediaGovernanceService} it trusts its
 * caller: who may change a folder's access, password and members (its owner or
 * `media.manage-all`, {@see FolderAccess::canAdminister()}) is decided by the
 * page actions, which re-check inside their handlers. Code that calls the
 * service directly owns that check.
 */
final readonly class MediaFolderService
{
    private const NAME_MAX = 120;

    public const int PASSWORD_MIN = 8;

    public function __construct(
        private ConnectionInterface $connection,
        private Dispatcher $events,
    ) {}

    /**
     * Create a folder under `$parent`, or at the top level when null.
     *
     * `$siteId` tags a top-level folder with the host's site (SPEC-0103 §9,
     * decision 4); a folder with a parent belongs to its root's site, so it is ignored there.
     *
     * @throws MediaException
     */
    public function create(?MediaFolder $parent, string $name, string $actorId, ?int $siteId = null): MediaFolder
    {
        $name = $this->cleanName($name);

        return $this->connection->transaction(function () use ($parent, $name, $actorId, $siteId): MediaFolder {
            $parent = $parent instanceof MediaFolder ? $this->current($parent) : null;
            $depth = ($parent->depth ?? 0) + 1;

            if ($depth > MediaConfig::maxFolderDepth()) {
                throw FolderTooDeep::make(MediaConfig::maxFolderDepth());
            }

            $this->assertNameFree($parent?->id, $name);

            $actor = is_numeric($actorId) ? (int) $actorId : null;

            // The path needs the new id, so it is written in a second statement.
            $folder = MediaFolder::query()->create([
                'uuid' => (string) Str::uuid(),
                'parent_id' => $parent?->id,
                'name' => $name,
                'path' => '/',
                'depth' => $depth,
                'access' => 'inherit',
                'owner_id' => $actor,
                'created_by' => $actor,
                'site_id' => $parent instanceof MediaFolder ? null : $siteId,
            ]);

            $folder->forceFill(['path' => ($parent->path ?? '/').$folder->id.'/'])->save();

            $this->events->dispatch(new FolderCreated($folder->id, $folder->uuid, $parent?->id, $depth, $actorId));

            return $folder;
        });
    }

    /** @throws MediaException */
    public function rename(MediaFolder $folder, string $name, string $actorId): MediaFolder
    {
        $name = $this->cleanName($name);

        return $this->connection->transaction(function () use ($folder, $name, $actorId): MediaFolder {
            $folder = $this->current($folder);

            if ($folder->name === $name) {
                return $folder;
            }

            $this->assertNameFree($folder->parent_id, $name, $folder->id);

            $folder->forceFill(['name' => $name])->save();

            $this->events->dispatch(new FolderRenamed($folder->id, $folder->uuid, $actorId));

            return $folder;
        });
    }

    /**
     * Move a folder, and everything in it, under `$to` (null: the top level).
     *
     * @throws FolderCycle|FolderTooDeep|FolderNameTaken
     */
    public function move(MediaFolder $folder, ?MediaFolder $to, string $actorId): MediaFolder
    {
        return $this->connection->transaction(fn (): MediaFolder => $this->relocate($folder, $to, $actorId));
    }

    /**
     * Delete an empty folder. A folder holding sub-folders or assets is refused:
     * nothing is deleted implicitly, and nothing cascades.
     *
     * @throws FolderNotEmpty
     */
    public function delete(MediaFolder $folder, string $actorId): void
    {
        $this->connection->transaction(function () use ($folder, $actorId): void {
            $folder = $this->current($folder);

            if ($folder->children()->exists() || $folder->assets()->exists()) {
                throw FolderNotEmpty::make();
            }

            $folder->delete();

            $this->events->dispatch(new FolderDeleted($folder->id, $folder->uuid, $actorId));
        });
    }

    /**
     * The explicit alternative to {@see delete()}: lift the sub-folders and assets
     * into the parent (or the root), then delete the now-empty folder. All or
     * nothing; a lifted sub-folder whose name is already taken in the parent
     * refuses the whole operation.
     *
     * @throws FolderNameTaken|MediaException
     */
    public function deleteMovingContentsToParent(MediaFolder $folder, string $actorId): void
    {
        $this->connection->transaction(function () use ($folder, $actorId): void {
            $folder = $this->current($folder);
            $parent = $folder->parent_id === null ? null : MediaFolder::query()->findOrFail($folder->parent_id);

            foreach ($folder->children()->get() as $child) {
                // The folder being deleted is still a sibling of what moves up; it must not count as a name clash.
                $this->relocate($child, $parent, $actorId, ignoreSiblingId: $folder->id);
            }

            $this->moveAssets(array_values($folder->assets()->get()->all()), $parent, $actorId);

            $this->delete($folder, $actorId);
        });
    }

    /**
     * File assets into a folder (null: the root). Assets already there are skipped.
     *
     * @param  list<MediaAsset>  $assets
     * @return int how many assets actually changed folder
     */
    public function moveAssets(array $assets, ?MediaFolder $to, string $actorId): int
    {
        return $this->connection->transaction(function () use ($assets, $to, $actorId): int {
            $to = $to instanceof MediaFolder ? $this->current($to) : null;
            $moved = 0;

            foreach ($assets as $asset) {
                $asset->refresh();

                if ($asset->folder_id === $to?->id) {
                    continue;
                }

                $from = $asset->folder_id;
                $asset->forceFill(['folder_id' => $to?->id])->save();

                $this->events->dispatch(new AssetMoved($asset->id, $from, $to?->id, $actorId));
                $moved++;
            }

            return $moved;
        });
    }

    /**
     * Set how a folder is protected.
     *
     * A password (at least eight characters) is required to switch a folder to
     * `password`, and replaces the old one when given for a folder that already
     * has one; omitted there, the current password stays. It is stored only as a
     * `Hash::make()` hash and appears in no event, message or log. A new hash is
     * a new fingerprint, so every unlock held in a session stops working
     * (SPEC-0103 §5.3: changing the password relocks the folder for everyone);
     * leaving `password` mode discards the hash. Members are kept across mode
     * changes and ignored unless the mode is `members`.
     *
     * @throws InvalidFolderAccess
     */
    public function setAccess(MediaFolder $folder, FolderAccessMode $mode, ?string $password, string $actorId): MediaFolder
    {
        if ($password !== null && $password !== '' && $mode !== FolderAccessMode::Password) {
            throw InvalidFolderAccess::make('A password only applies to a password-protected folder.');
        }

        if ($mode === FolderAccessMode::Password && $password !== null && $password !== '' && mb_strlen($password) < self::PASSWORD_MIN) {
            throw InvalidFolderAccess::make(sprintf('A folder password needs at least %d characters.', self::PASSWORD_MIN));
        }

        return $this->connection->transaction(function () use ($folder, $mode, $password, $actorId): MediaFolder {
            $folder = $this->current($folder);
            $from = $folder->accessMode();
            $hasPassword = is_string($folder->getAttribute('password_hash')) && $folder->getAttribute('password_hash') !== '';
            $changed = $password !== null && $password !== '';

            if ($mode === FolderAccessMode::Password && ! $changed && ! ($from === FolderAccessMode::Password && $hasPassword)) {
                throw InvalidFolderAccess::make('A password-protected folder needs a password.');
            }

            if ($from === $mode && ! $changed) {
                return $folder;
            }

            $folder->forceFill(['access' => $mode->value]);

            if ($mode !== FolderAccessMode::Password) {
                $folder->forceFill(['password_hash' => null]);
            } elseif ($changed) {
                $folder->forceFill(['password_hash' => Hash::make((string) $password)]);
            }

            $folder->save();

            $this->events->dispatch(new FolderAccessChanged($folder->id, $from->value, $mode->value, $folder->members()->count(), $actorId));

            return $folder;
        });
    }

    /**
     * Add a member, or change an existing member's role.
     *
     * @param  string  $role  `view` or `manage`; anything else is refused
     *
     * @throws InvalidFolderAccess
     */
    public function addMember(MediaFolder $folder, int|string $userId, string $role, string $actorId): void
    {
        $userId = $this->memberId($userId);

        if (! in_array($role, ['view', 'manage'], true)) {
            throw InvalidFolderAccess::make('A member role is "view" or "manage".');
        }

        $this->connection->transaction(function () use ($folder, $userId, $role, $actorId): void {
            $folder = $this->current($folder);
            $previous = MediaFolderMember::query()->where('media_folder_id', $folder->id)->where('user_id', $userId)->value('role');

            if ($previous === $role) {
                return;
            }

            MediaFolderMember::query()->upsert(
                [['media_folder_id' => $folder->id, 'user_id' => $userId, 'role' => $role]],
                ['media_folder_id', 'user_id'],
                ['role'],
            );

            app(FolderAccess::class)->forget();

            $this->events->dispatch(new FolderMembersChanged($folder->id, $userId, $previous === null ? 'added' : 'role_changed', $role, $folder->members()->count(), $actorId));
        });
    }

    /** @throws InvalidFolderAccess */
    public function removeMember(MediaFolder $folder, int|string $userId, string $actorId): void
    {
        $userId = $this->memberId($userId);

        $this->connection->transaction(function () use ($folder, $userId, $actorId): void {
            $folder = $this->current($folder);

            $removed = MediaFolderMember::query()->where('media_folder_id', $folder->id)->where('user_id', $userId)->delete();

            app(FolderAccess::class)->forget();

            if ($removed > 0) {
                $this->events->dispatch(new FolderMembersChanged($folder->id, $userId, 'removed', null, $folder->members()->count(), $actorId));
            }
        });
    }

    /** @throws InvalidFolderAccess */
    private function memberId(int|string $userId): int
    {
        if (! is_numeric($userId) || (int) $userId < 1) {
            throw InvalidFolderAccess::make('A member is a user id.');
        }

        return (int) $userId;
    }

    /** Must run inside a transaction. */
    private function relocate(MediaFolder $folder, ?MediaFolder $to, string $actorId, ?int $ignoreSiblingId = null): MediaFolder
    {
        $folder = $this->current($folder);
        $to = $to instanceof MediaFolder ? $this->current($to) : null;

        if ($to instanceof MediaFolder && $folder->contains($to)) {
            throw FolderCycle::make();
        }

        if ($folder->parent_id === $to?->id) {
            return $folder;
        }

        $depth = ($to->depth ?? 0) + 1;
        $shift = $depth - $folder->depth;
        $subtree = $folder->subtreeQuery()->get(['id', 'path', 'depth']);
        $deepest = 0;

        foreach ($subtree as $row) {
            $deepest = max($deepest, $row->depth);
        }

        if ($deepest + $shift > MediaConfig::maxFolderDepth()) {
            throw FolderTooDeep::make(MediaConfig::maxFolderDepth());
        }

        $this->assertNameFree($to?->id, $folder->name, $folder->id, $ignoreSiblingId);

        $oldPath = $folder->path;
        $newPath = ($to->path ?? '/').$folder->id.'/';

        // Every row below keeps its own tail of the path; only the head changes.
        foreach ($subtree as $row) {
            MediaFolder::query()->whereKey($row->id)->update([
                'path' => $newPath.substr($row->path, strlen($oldPath)),
                'depth' => $row->depth + $shift,
            ]);
        }

        $fromParent = $folder->parent_id;

        // A site tag only means something on a top-level folder.
        MediaFolder::query()->whereKey($folder->id)->update(
            ['parent_id' => $to?->id] + ($to instanceof MediaFolder ? ['site_id' => null] : []),
        );

        $this->events->dispatch(new FolderMoved($folder->id, $fromParent, $to?->id, $actorId));

        return $this->current($folder);
    }

    /** The row as it is now: a model held across a request may predate another folder's move. */
    private function current(MediaFolder $folder): MediaFolder
    {
        return MediaFolder::query()->findOrFail($folder->id);
    }

    /** @throws InvalidFolderName */
    private function cleanName(string $name): string
    {
        $name = preg_replace('/^\s+|\s+$/u', '', $name);

        if ($name === null) {
            throw InvalidFolderName::make('The folder name is not valid text.');
        }

        if ($name === '') {
            throw InvalidFolderName::make('A folder needs a name.');
        }

        if (mb_strlen($name) > self::NAME_MAX) {
            throw InvalidFolderName::make(sprintf('A folder name can be at most %d characters.', self::NAME_MAX));
        }

        if (preg_match('/[\p{Cc}\/]/u', $name) !== 0) {
            throw InvalidFolderName::make('A folder name cannot contain a slash or a control character.');
        }

        return $name;
    }

    /** @throws FolderNameTaken */
    private function assertNameFree(?int $parentId, string $name, ?int $exceptId = null, ?int $ignoreSiblingId = null): void
    {
        $siblings = MediaFolder::query()->select(['id', 'name']);

        $parentId === null ? $siblings->whereNull('parent_id') : $siblings->where('parent_id', $parentId);

        $wanted = mb_strtolower($name);

        foreach ($siblings->get() as $sibling) {
            if ($sibling->id !== $exceptId && $sibling->id !== $ignoreSiblingId && mb_strtolower($sibling->name) === $wanted) {
                throw FolderNameTaken::make();
            }
        }
    }
}
