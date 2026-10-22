<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Access;

use Banyan\FilamentMediaLibrary\Enums\FolderAccessMode;
use Banyan\FilamentMediaLibrary\Events\FolderUnlockFailed;
use Banyan\FilamentMediaLibrary\Models\MediaAsset;
use Banyan\FilamentMediaLibrary\Models\MediaFolder;
use Banyan\FilamentMediaLibrary\Models\MediaFolderMember;
use Banyan\FilamentMediaLibrary\Support\MediaConfig;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The one place a folder decision is made (SPEC-0103 §5.2, §5.3).
 *
 * Every surface (grid, tree, picker, asset page, file routes, URL helpers)
 * asks here. Nothing else may reimplement "can this user see that folder".
 *
 * ## The rules
 *
 * A folder's **mode** is its own `access` column; its **effective mode** is the
 * nearest self-or-ancestor that is not `inherit` (a top-level `inherit` is
 * `shared`). That is what the interface shows. What is *enforced* is stricter,
 * deliberately: **a folder is open to a user only if every folder on the path
 * from the top down to it is.** A `shared` sub-folder of a members-only folder
 * therefore stays closed to non-members, and so does its path in a breadcrumb
 * or a picker label. Access can narrow as you go down the tree, never widen.
 * (Read literally, "nearest wins" would let a child re-publish a parent's
 * contents and would leak the parent's name through the child's path.)
 *
 * Per folder on that path:
 *  - `shared`/`inherit`: nothing extra (the library-wide `media.view` / `media.manage` apply);
 *  - `members`: the folder's owner or a member (`view` or `manage` to browse,
 *    `manage` to change things). The ability `media.manage` is not needed: the
 *    role is the grant;
 *  - `password`: `media.view` / `media.manage` AND the folder unlocked in this
 *    session. The folder's owner and `media.manage-all` do **not** need to unlock
 *    (the owner chose the password; the administrator can reset it anyway, so
 *    making either type it would protect nothing).
 *
 * `media.manage-all` passes everything. A user who is not signed in passes
 * nothing; an unknown folder id passes nothing. Unknown access values in the
 * database are read as `members`, the closed mode.
 *
 * ## Visibility
 *
 * `browsableFolderIds()` and `listedFolderIds()` compute, in PHP over the whole
 * folder table (one query, cached for the request), the folders a user may
 * open and the folders they may merely see in a tree. Libraries have tens to a
 * few thousand folders, so this is cheaper and far easier to get right than a
 * recursive SQL expression on three database engines. The model scopes
 * `visibleTo()` turn the id lists into `IN (...)` clauses. A list is *allow*,
 * never *deny*: a folder created after the list was made is invisible, not exposed.
 *
 * Registered as a scoped binding, so the folder cache lives for one request;
 * the folder and member models flush it on every write.
 */
final class FolderAccess
{
    /** @var array<int, array{id: int, uuid: string, parent: int|null, mode: FolderAccessMode, owner: string|null, hash: string|null}>|null */
    private ?array $rows = null;

    /** @var array<int, array<int, string>> */
    private array $roles = [];

    public function __construct(private readonly Dispatcher $events) {}

    /** Drop the cached folder table; called by the models whenever a folder or member changes. */
    public function forget(): void
    {
        $this->rows = null;
        $this->roles = [];
    }

    // ------------------------------------------------------------------ decisions

    /** Holds `media.manage-all`: every folder is open to them, whatever its mode. */
    public function seesEverything(?Authenticatable $user = null): bool
    {
        $ctx = $this->context($user);

        return $ctx instanceof AccessContext && $ctx->manageAll;
    }

    /** May this user see inside the folder (or the folder an asset is in)? Null is the library root. */
    public function canBrowse(MediaFolder|MediaAsset|null $target, ?Authenticatable $user = null): bool
    {
        $ctx = $this->context($user);

        return $ctx instanceof AccessContext && $this->browseAllowed(self::idOf($target), $ctx);
    }

    /** May this user upload, edit and delete inside it? */
    public function canManage(MediaFolder|MediaAsset|null $target, ?Authenticatable $user = null): bool
    {
        $ctx = $this->context($user);

        return $ctx instanceof AccessContext && $this->manageAllowed(self::idOf($target), $ctx);
    }

    /**
     * May this user change the folder's access, password and members? Only the
     * owner or `media.manage-all`, and only someone who could manage the folder anyway.
     */
    public function canAdminister(MediaFolder $folder, ?Authenticatable $user = null): bool
    {
        $ctx = $this->context($user);

        if (! $ctx instanceof AccessContext) {
            return false;
        }

        if ($ctx->manageAll) {
            return true;
        }

        $row = $this->rows()[$folder->id] ?? null;

        return $row !== null && $this->isOwner($row, $ctx->user) && $this->manageAllowed($folder->id, $ctx);
    }

    /**
     * Is the folder shown to this user at all: open, or a password folder
     * waiting to be unlocked? Everything above it must be open.
     */
    public function isListed(MediaFolder $folder, ?Authenticatable $user = null): bool
    {
        $ctx = $this->context($user);

        return $ctx instanceof AccessContext && $this->listedAllowed($folder->id, $ctx);
    }

    /** Shown, but its contents wait for a password. */
    public function isLocked(MediaFolder $folder, ?Authenticatable $user = null): bool
    {
        $ctx = $this->context($user);

        return $ctx instanceof AccessContext && $this->listedAllowed($folder->id, $ctx) && ! $this->browseAllowed($folder->id, $ctx);
    }

    /**
     * The folder whose password stands between the user and `$folder`: the
     * outermost locked one on its path (that is the one to unlock first).
     */
    public function blockingFolder(MediaFolder $folder, ?Authenticatable $user = null): ?MediaFolder
    {
        $ctx = $this->context($user);
        $id = $ctx instanceof AccessContext ? $this->blockingFolderId($folder->id, $ctx) : null;

        return $id === null ? null : MediaFolder::query()->find($id);
    }

    /** The mode shown for a folder: its nearest non-`inherit` self-or-ancestor, `shared` at the top. */
    public function effectiveMode(MediaFolder|MediaAsset|null $target): FolderAccessMode
    {
        return $this->modeOfId(self::idOf($target));
    }

    private function modeOfId(?int $id): FolderAccessMode
    {
        foreach ($this->chain($id) ?? [] as $row) {
            if ($row['mode'] !== FolderAccessMode::Inherit) {
                return $row['mode'];
            }
        }

        return FolderAccessMode::Shared;
    }

    /**
     * Whether any folder on the path is members-only or password-protected, so
     * that the files in it are not for the public (§5.4). Unlike browsing this
     * does not depend on who asks. An unknown folder counts as restricted.
     */
    public function isRestricted(MediaFolder|MediaAsset|null $target): bool
    {
        $id = self::idOf($target);

        return $id !== null && $this->restrictedId($id);
    }

    // ------------------------------------------------------------------ id sets for queries

    /**
     * Ids of the folders whose contents the user may see. The root is separate
     * ({@see canBrowse()} with null). Allow-list: see the class comment.
     *
     * @return list<int>
     */
    public function browsableFolderIds(?Authenticatable $user = null): array
    {
        $ctx = $this->context($user);
        $ids = [];

        foreach ($ctx instanceof AccessContext ? array_keys($this->rows()) : [] as $id) {
            if ($ctx instanceof AccessContext && $this->browseAllowed($id, $ctx)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Ids of the folders the user may see in a tree: the browsable ones plus
     * password folders (not what is inside them) waiting to be unlocked.
     *
     * @return list<int>
     */
    public function listedFolderIds(?Authenticatable $user = null): array
    {
        $ctx = $this->context($user);
        $ids = [];

        foreach ($ctx instanceof AccessContext ? array_keys($this->rows()) : [] as $id) {
            if ($ctx instanceof AccessContext && $this->listedAllowed($id, $ctx)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Ids of the password folders the user is shown but has not unlocked.
     *
     * @return list<int>
     */
    public function lockedFolderIds(?Authenticatable $user = null): array
    {
        $ctx = $this->context($user);
        $ids = [];

        foreach ($ctx instanceof AccessContext ? array_keys($this->rows()) : [] as $id) {
            if ($ctx instanceof AccessContext && $this->listedAllowed($id, $ctx) && ! $this->browseAllowed($id, $ctx)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Ids of the folders the user may upload to, edit in and delete from.
     *
     * @return list<int>
     */
    public function manageableFolderIds(?Authenticatable $user = null): array
    {
        $ctx = $this->context($user);
        $ids = [];

        foreach ($ctx instanceof AccessContext ? array_keys($this->rows()) : [] as $id) {
            if ($ctx instanceof AccessContext && $this->manageAllowed($id, $ctx)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Ids of the folders with no members-only or password folder on their path:
     * what a public page may use.
     *
     * @return list<int>
     */
    public function unrestrictedFolderIds(): array
    {
        return array_values(array_filter(array_keys($this->rows()), fn (int $id): bool => ! $this->restrictedId($id)));
    }

    // ------------------------------------------------------------------ passwords

    /**
     * Try a password for the password-protected folder that guards `$folder`
     * (itself or its nearest password ancestor).
     *
     * Returns false for a wrong password, for a user who may not get as far as
     * that folder, for a folder with no password, and while throttled: five wrong
     * attempts per user per folder per 15 minutes, after which even the right
     * password is refused until the window passes. Failures (and throttled
     * attempts) dispatch {@see FolderUnlockFailed}; the password appears nowhere.
     * Success is remembered in the session, server side, until
     * `folders.unlock_minutes` pass or the password changes.
     */
    public function unlock(MediaFolder $folder, string $password, ?Authenticatable $user = null): bool
    {
        $ctx = $this->context($user);
        $target = $this->nearestPasswordRow($folder->id);

        if (! $ctx instanceof AccessContext || $target === null || ! $ctx->browse || ! $this->parentsPass($target, $ctx)) {
            return false;
        }

        $key = $this->throttleKey($ctx->user, $target['uuid']);

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            $this->failed($target['id'], $this->attemptsOf($key), $ctx->user);

            return false;
        }

        $hash = $target['hash'];

        if ($hash === null || $hash === '' || ! Hash::check($password, $hash)) {
            RateLimiter::hit($key, self::WINDOW_SECONDS);
            $this->failed($target['id'], $this->attemptsOf($key), $ctx->user);

            return false;
        }

        RateLimiter::clear($key);

        $this->session()->put(self::sessionKey($target['uuid']), [
            'until' => now()->addMinutes(max(1, MediaConfig::int('folders.unlock_minutes', 30)))->getTimestamp(),
            'fp' => $this->fingerprint($hash),
        ]);

        return true;
    }

    /** Is the password folder (the one guarding `$folder`) unlocked in this session, right now? */
    public function isUnlocked(MediaFolder $folder): bool
    {
        $row = $this->nearestPasswordRow($folder->id);

        return $row !== null && $this->unlockedRow($row);
    }

    /** Forget the unlock of the password folder guarding `$folder`. */
    public function lock(MediaFolder $folder): void
    {
        $row = $this->nearestPasswordRow($folder->id);

        if ($row !== null) {
            $this->session()->forget(self::sessionKey($row['uuid']));
        }
    }

    /**
     * Has this user used up their attempts on the folder's password for now?
     *
     * @phpstan-impure
     */
    public function isThrottled(MediaFolder $folder, ?Authenticatable $user = null): bool
    {
        $user ??= auth()->user();
        $row = $this->nearestPasswordRow($folder->id);

        return $user instanceof Authenticatable && $row !== null
            && RateLimiter::tooManyAttempts($this->throttleKey($user, $row['uuid']), self::MAX_ATTEMPTS);
    }

    /**
     * Seconds until another attempt is allowed; 0 when not throttled.
     *
     * @phpstan-impure
     */
    public function retryAfter(MediaFolder $folder, ?Authenticatable $user = null): int
    {
        $user ??= auth()->user();
        $row = $this->nearestPasswordRow($folder->id);

        return $user instanceof Authenticatable && $row !== null
            ? RateLimiter::availableIn($this->throttleKey($user, $row['uuid']))
            : 0;
    }

    /** The session key an unlock is stored under, for tests and for hosts that clear it on logout. */
    public static function sessionKey(string $folderUuid): string
    {
        return 'media-library.unlocked.'.$folderUuid;
    }

    private const int MAX_ATTEMPTS = 5;

    private const int WINDOW_SECONDS = 900;

    // ------------------------------------------------------------------ internals

    /** Who is asking, with the three library abilities read once; null when nobody is signed in. */
    private function context(?Authenticatable $user): ?AccessContext
    {
        $user ??= auth()->user();

        if (! $user instanceof Authenticatable) {
            return null;
        }

        return new AccessContext(
            $user,
            MediaLibraryGate::allowsManageAll($user),
            MediaLibraryGate::allowsBrowse($user),
            MediaLibraryGate::allowsManage($user),
        );
    }

    private function browseAllowed(?int $id, AccessContext $ctx): bool
    {
        if ($ctx->manageAll) {
            return true;
        }

        return $id === null ? $ctx->browse : $this->stateOf($id, $ctx) === FolderState::Pass;
    }

    private function manageAllowed(?int $id, AccessContext $ctx): bool
    {
        if ($ctx->manageAll) {
            return true;
        }

        if ($id === null) {
            return $ctx->manage;
        }

        $chain = $this->chain($id);

        if ($chain === null || $this->stateOf($id, $ctx) !== FolderState::Pass) {
            return false;
        }

        foreach ($chain as $row) {
            if (! $this->ownManage($row, $ctx)) {
                return false;
            }
        }

        // A members folder's roles are the grant; everywhere else the library-wide ability is also needed.
        return $this->modeOfId($id) === FolderAccessMode::Members || $ctx->manage;
    }

    private function listedAllowed(int $id, AccessContext $ctx): bool
    {
        $state = $this->stateOf($id, $ctx);

        return $state === FolderState::Pass || ($state === FolderState::Locked && $this->blockingFolderId($id, $ctx) === $id);
    }

    /** The browse state of a path: the worst of its folders; `Deny` for an unknown folder. */
    private function stateOf(int $id, AccessContext $ctx): FolderState
    {
        $chain = $this->chain($id);

        if ($chain === null) {
            return FolderState::Deny;
        }

        if ($ctx->manageAll) {
            return FolderState::Pass;
        }

        if (! $ctx->browse) {
            return FolderState::Deny;
        }

        $state = FolderState::Pass;

        foreach ($chain as $row) {
            $state = $state->worse($this->ownBrowse($row, $ctx->user));
        }

        return $state;
    }

    /** The outermost folder on the path that is locked, if nothing above or at it is closed. */
    private function blockingFolderId(int $id, AccessContext $ctx): ?int
    {
        $chain = $this->chain($id);

        if ($chain === null || ! $ctx->browse || $ctx->manageAll) {
            return null;
        }

        // The chain runs from the folder up; walk it from the top down.
        foreach (array_reverse($chain) as $row) {
            $own = $this->ownBrowse($row, $ctx->user);

            if ($own === FolderState::Deny) {
                return null;
            }

            if ($own === FolderState::Locked) {
                return $row['id'];
            }
        }

        return null;
    }

    private function restrictedId(int $id): bool
    {
        $chain = $this->chain($id);

        if ($chain === null) {
            return true;
        }

        foreach ($chain as $row) {
            if ($row['mode']->isRestricted()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{id: int, uuid: string, parent: int|null, mode: FolderAccessMode, owner: string|null, hash: string|null}  $row
     */
    private function ownBrowse(array $row, Authenticatable $user): FolderState
    {
        return match ($row['mode']) {
            FolderAccessMode::Inherit, FolderAccessMode::Shared => FolderState::Pass,
            FolderAccessMode::Members => $this->isOwner($row, $user) || $this->roleOf($row['id'], $user) !== null ? FolderState::Pass : FolderState::Deny,
            FolderAccessMode::Password => $this->isOwner($row, $user) || $this->unlockedRow($row) ? FolderState::Pass : FolderState::Locked,
        };
    }

    /**
     * @param  array{id: int, uuid: string, parent: int|null, mode: FolderAccessMode, owner: string|null, hash: string|null}  $row
     */
    private function ownManage(array $row, AccessContext $ctx): bool
    {
        return match ($row['mode']) {
            FolderAccessMode::Inherit, FolderAccessMode::Shared => true,
            FolderAccessMode::Members => $this->isOwner($row, $ctx->user) || $this->roleOf($row['id'], $ctx->user) === 'manage',
            FolderAccessMode::Password => $ctx->manage,
        };
    }

    /**
     * @param  array{id: int, uuid: string, parent: int|null, mode: FolderAccessMode, owner: string|null, hash: string|null}  $row
     */
    private function isOwner(array $row, Authenticatable $user): bool
    {
        return $row['owner'] !== null && $row['owner'] === MediaLibraryGate::identifier($user);
    }

    private function roleOf(int $folderId, Authenticatable $user): ?string
    {
        $userId = $user->getAuthIdentifier();

        if (! is_numeric($userId)) {
            return null;
        }

        $this->roles[(int) $userId] ??= array_map(
            static fn (mixed $role): string => is_string($role) ? $role : '',
            MediaFolderMember::query()->where('user_id', (int) $userId)->pluck('role', 'media_folder_id')->all(),
        );

        $role = $this->roles[(int) $userId][$folderId] ?? null;

        return in_array($role, ['view', 'manage'], true) ? $role : null;
    }

    /**
     * @param  array{id: int, uuid: string, parent: int|null, mode: FolderAccessMode, owner: string|null, hash: string|null}  $row
     */
    private function unlockedRow(array $row): bool
    {
        $entry = $this->session()->get(self::sessionKey($row['uuid']));

        if (! is_array($entry) || ! is_int($entry['until'] ?? null) || ! is_string($entry['fp'] ?? null)) {
            return false;
        }

        $hash = $row['hash'];

        // The fingerprint is what makes "changing the password relocks everyone" true.
        return $hash !== null && $hash !== ''
            && $entry['until'] > now()->getTimestamp()
            && hash_equals($this->fingerprint($hash), $entry['fp']);
    }

    private function fingerprint(string $hash): string
    {
        $key = config('app.key');

        return hash_hmac('sha256', $hash, is_string($key) ? $key : '');
    }

    private function throttleKey(Authenticatable $user, string $folderUuid): string
    {
        return 'media-library:unlock:'.sha1($user::class.'|'.MediaLibraryGate::identifier($user)).':'.$folderUuid;
    }

    private function attemptsOf(string $key): int
    {
        $attempts = RateLimiter::attempts($key);

        return is_numeric($attempts) ? (int) $attempts : 0;
    }

    private function failed(int $folderId, int $attempts, Authenticatable $user): void
    {
        $this->events->dispatch(new FolderUnlockFailed($folderId, $attempts, MediaLibraryGate::identifier($user)));
    }

    /**
     * @param  array{id: int, uuid: string, parent: int|null, mode: FolderAccessMode, owner: string|null, hash: string|null}  $row
     */
    private function parentsPass(array $row, AccessContext $ctx): bool
    {
        return $ctx->manageAll || $row['parent'] === null || $this->stateOf($row['parent'], $ctx) === FolderState::Pass;
    }

    /** @return array{id: int, uuid: string, parent: int|null, mode: FolderAccessMode, owner: string|null, hash: string|null}|null */
    private function nearestPasswordRow(int $id): ?array
    {
        foreach ($this->chain($id) ?? [] as $row) {
            if ($row['mode'] === FolderAccessMode::Password) {
                return $row;
            }
        }

        return null;
    }

    /**
     * The folder and its ancestors, nearest first; null when the folder is unknown or the tree is corrupt (a cycle).
     *
     * @return list<array{id: int, uuid: string, parent: int|null, mode: FolderAccessMode, owner: string|null, hash: string|null}>|null
     */
    private function chain(?int $id): ?array
    {
        if ($id === null) {
            return [];
        }

        $rows = $this->rows();

        // A folder the cache has not seen may have been made a moment ago.
        if (! isset($rows[$id])) {
            $this->forget();
            $rows = $this->rows();
        }

        $chain = [];
        $seen = [];

        for ($next = $id; $next !== null; $next = $rows[$next]['parent']) {
            if (! isset($rows[$next]) || isset($seen[$next])) {
                return null;
            }

            $seen[$next] = true;
            $chain[] = $rows[$next];
        }

        return $chain;
    }

    /** @return array<int, array{id: int, uuid: string, parent: int|null, mode: FolderAccessMode, owner: string|null, hash: string|null}> */
    private function rows(): array
    {
        if ($this->rows !== null) {
            return $this->rows;
        }

        $rows = [];

        foreach (MediaFolder::query()->get(['id', 'uuid', 'parent_id', 'access', 'owner_id', 'password_hash']) as $folder) {
            $rows[$folder->id] = [
                'id' => $folder->id,
                'uuid' => $folder->uuid,
                'parent' => $folder->parent_id,
                // An access value this code does not know is read as the closed one.
                'mode' => FolderAccessMode::tryFrom($folder->access) ?? FolderAccessMode::Members,
                'owner' => $folder->owner_id === null ? null : (string) $folder->owner_id,
                'hash' => is_string($folder->getAttribute('password_hash')) ? $folder->getAttribute('password_hash') : null,
            ];
        }

        return $this->rows = $rows;
    }

    private function session(): Session
    {
        /** @var Session */
        return app('session.store');
    }

    private static function idOf(MediaFolder|MediaAsset|null $target): ?int
    {
        return $target instanceof MediaAsset ? $target->folder_id : $target?->id;
    }
}
