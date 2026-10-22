<?php

declare(strict_types=1);

use Banyan\FilamentMediaLibrary\Access\FolderAccess;
use Banyan\FilamentMediaLibrary\Access\MediaLibraryGate;
use Banyan\FilamentMediaLibrary\Enums\FolderAccessMode;
use Banyan\FilamentMediaLibrary\Events\FolderAccessChanged;
use Banyan\FilamentMediaLibrary\Events\FolderMembersChanged;
use Banyan\FilamentMediaLibrary\Events\FolderUnlockFailed;
use Banyan\FilamentMediaLibrary\Events\MediaEvent;
use Banyan\FilamentMediaLibrary\Exceptions\InvalidFolderAccess;
use Banyan\FilamentMediaLibrary\Folders\MediaFolderService;
use Banyan\FilamentMediaLibrary\Models\MediaAsset;
use Banyan\FilamentMediaLibrary\Models\MediaFolder;
use Banyan\FilamentMediaLibrary\Tests\Support\AccessWorld as W;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;

covers(FolderAccess::class, MediaFolderService::class, MediaLibraryGate::class, MediaFolder::class, MediaAsset::class);

afterEach(function (): void {
    MediaLibraryGate::manageAllUsing(null);
    MediaLibraryGate::manageUsing(null);
});

/** Every MediaEvent dispatched while the test runs, collected through a real listener. */
function collectEvents(): ArrayObject
{
    $events = new ArrayObject;
    Event::listen(MediaEvent::class, static function (MediaEvent $event) use ($events): void {
        $events[] = $event;
    });

    return $events;
}

function access(): FolderAccess
{
    return app(FolderAccess::class);
}

// ---------------------------------------------------------------- the ability

it('keeps media.manage-all closed until it is defined, and then follows the Gate', function (): void {
    $user = W::user('Admin');

    expect(MediaLibraryGate::allowsManageAll($user))->toBeFalse();

    Gate::define('media.manage-all', static fn (): bool => true);

    expect(MediaLibraryGate::allowsManageAll($user))->toBeTrue();

    Gate::define('media.manage-all', static fn (): bool => false);

    expect(MediaLibraryGate::allowsManageAll($user))->toBeFalse();
});

it('counts a Gate::before grant for media.manage-all and honours a renamed ability', function (): void {
    $admin = W::user('Admin');
    $other = W::user('Other');
    Gate::before(static fn ($user, string $ability): ?bool => $ability === 'library.everything' && $user->name === 'Admin' ? true : null);
    config()->set('media-library.abilities', ['manage_all' => 'library.everything']);

    expect(MediaLibraryGate::allowsManageAll($admin))->toBeTrue()
        ->and(MediaLibraryGate::allowsManageAll($other))->toBeFalse();
});

it('asks the manage-all fallback only for the signed-in user, never for someone else', function (): void {
    $admin = W::user('Admin');
    $other = W::user('Other');
    MediaLibraryGate::manageAllUsing(static fn (): bool => true);

    expect(MediaLibraryGate::allowsManageAll($admin))->toBeFalse();

    $this->actingAs($admin);

    expect(MediaLibraryGate::allowsManageAll())->toBeTrue()
        ->and(MediaLibraryGate::allowsManageAll($admin))->toBeTrue()
        // A closure can only speak for whoever is signed in; for another user the answer is no, not a guess.
        ->and(MediaLibraryGate::allowsManageAll($other))->toBeFalse();
});

it('admits nobody who is not signed in, to anything', function (): void {
    $folder = W::folder('Anyone');
    $asset = W::asset('visible', $folder);
    Gate::define('media.manage', static fn (): bool => true);
    Gate::define('media.manage-all', static fn (): bool => true);

    expect(access()->canBrowse(null))->toBeFalse()
        ->and(access()->canBrowse($folder))->toBeFalse()
        ->and(access()->canBrowse($asset))->toBeFalse()
        ->and(access()->canManage($folder))->toBeFalse()
        ->and(access()->canManage(null))->toBeFalse()
        ->and(access()->canAdminister($folder))->toBeFalse()
        ->and(access()->seesEverything())->toBeFalse()
        ->and(access()->browsableFolderIds())->toBe([])
        ->and(access()->listedFolderIds())->toBe([])
        ->and(MediaAsset::query()->visibleTo()->count())->toBe(0)
        ->and(MediaFolder::query()->visibleTo()->count())->toBe(0);
});

// ---------------------------------------------------------------- shared and the root

it('leaves the root and shared folders to the library-wide abilities, as before', function (): void {
    $user = W::user('Editor');
    $folder = W::folder('Shared');
    $asset = W::asset('plain', $folder);

    expect(access()->canBrowse(null, $user))->toBeTrue()
        ->and(access()->canBrowse($folder, $user))->toBeTrue()
        ->and(access()->canBrowse($asset, $user))->toBeTrue()
        // media.manage is undefined, so managing is closed
        ->and(access()->canManage(null, $user))->toBeFalse()
        ->and(access()->canManage($folder, $user))->toBeFalse();

    Gate::define('media.manage', static fn (): bool => true);

    expect(access()->canManage(null, $user))->toBeTrue()
        ->and(access()->canManage($folder, $user))->toBeTrue()
        ->and(access()->canManage($asset, $user))->toBeTrue()
        ->and($folder->isRestricted())->toBeFalse()
        ->and($asset->isRestricted())->toBeFalse();

    Gate::define('media.view', static fn (): bool => false);

    expect(access()->canBrowse($folder, $user))->toBeFalse()
        ->and(access()->canBrowse(null, $user))->toBeFalse();
});

it('reads the nearest protected self-or-ancestor as the effective mode, and shared at the top', function (): void {
    $top = W::folder('Top');
    $members = W::restricted('Members', FolderAccessMode::Members, parent: $top);
    $child = W::folder('Child', $members);
    $grandchild = W::folder('Grandchild', $child);
    $override = app(MediaFolderService::class)->setAccess(W::folder('Open again', $child), FolderAccessMode::Shared, null, '1');

    expect($top->effectiveAccess())->toBe(FolderAccessMode::Shared)
        ->and($top->accessMode())->toBe(FolderAccessMode::Inherit)
        ->and($members->effectiveAccess())->toBe(FolderAccessMode::Members)
        ->and($grandchild->effectiveAccess())->toBe(FolderAccessMode::Members)
        ->and($grandchild->accessMode())->toBe(FolderAccessMode::Inherit)
        ->and($override->effectiveAccess())->toBe(FolderAccessMode::Shared);
});

// ---------------------------------------------------------------- members

it('shows a members folder only to its owner, its members and manage-all', function (): void {
    $owner = W::user('Owner');
    $viewer = W::user('Viewer');
    $manager = W::user('Manager');
    $outsider = W::user('Outsider');
    $admin = W::user('Admin');
    $folder = W::restricted('Payroll', FolderAccessMode::Members, $owner);
    $asset = W::asset('payslip', $folder);
    W::member($folder, $viewer, 'view');
    W::member($folder, $manager, 'manage');
    Gate::define('media.manage-all', static fn ($user): bool => $user->name === 'Admin');

    foreach ([$owner, $viewer, $manager, $admin] as $allowed) {
        expect(access()->canBrowse($folder, $allowed))->toBeTrue($allowed->name)
            ->and(access()->canBrowse($asset, $allowed))->toBeTrue($allowed->name)
            ->and(access()->isListed($folder, $allowed))->toBeTrue($allowed->name);
    }

    expect(access()->canBrowse($folder, $outsider))->toBeFalse()
        ->and(access()->canBrowse($asset, $outsider))->toBeFalse()
        ->and(access()->isListed($folder, $outsider))->toBeFalse()
        ->and(access()->browsableFolderIds($outsider))->toBe([])
        ->and(access()->listedFolderIds($outsider))->toBe([])
        ->and(MediaAsset::query()->visibleTo($outsider)->pluck('id')->all())->toBe([])
        ->and(MediaFolder::query()->visibleTo($outsider)->count())->toBe(0)
        ->and(MediaAsset::query()->visibleTo($viewer)->pluck('id')->all())->toBe([$asset->id]);
});

it('lets a view member browse but not manage, and a manage member manage without media.manage', function (): void {
    $owner = W::user('Owner');
    $viewer = W::user('Viewer');
    $manager = W::user('Manager');
    $folder = W::restricted('Team', FolderAccessMode::Members, $owner);
    $asset = W::asset('team-file', $folder);
    W::member($folder, $viewer, 'view');
    W::member($folder, $manager, 'manage');

    // No media.manage anywhere: the role in the folder is the grant.
    expect(access()->canManage($folder, $viewer))->toBeFalse()
        ->and(access()->canManage($asset, $viewer))->toBeFalse()
        ->and(access()->canManage($folder, $manager))->toBeTrue()
        ->and(access()->canManage($asset, $manager))->toBeTrue()
        ->and(access()->canManage($folder, $owner))->toBeTrue()
        // ... and the same person cannot manage the root, which still follows media.manage
        ->and(access()->canManage(null, $manager))->toBeFalse()
        ->and(access()->manageableFolderIds($manager))->toBe([$folder->id])
        ->and(access()->manageableFolderIds($viewer))->toBe([]);
});

it('does not let a member of one folder into another', function (): void {
    $a = W::restricted('Folder A', FolderAccessMode::Members);
    $b = W::restricted('Folder B', FolderAccessMode::Members);
    $user = W::user('Only A');
    W::member($a, $user, 'manage');

    expect(access()->canBrowse($a, $user))->toBeTrue()
        ->and(access()->canBrowse($b, $user))->toBeFalse()
        ->and(access()->canManage($b, $user))->toBeFalse();
});

it('lets manage-all see and manage every folder, but only with the ability', function (): void {
    $folder = W::restricted('Hidden', FolderAccessMode::Members);
    $locked = W::restricted('Locked', FolderAccessMode::Password);
    $asset = W::asset('hidden-file', $folder);
    $admin = W::user('Admin');
    $plain = W::user('Plain');
    Gate::define('media.manage-all', static fn ($user): bool => $user->name === 'Admin');

    expect(access()->canBrowse($folder, $admin))->toBeTrue()
        ->and(access()->canManage($folder, $admin))->toBeTrue()
        ->and(access()->canBrowse($locked, $admin))->toBeTrue()
        ->and(access()->canAdminister($folder, $admin))->toBeTrue()
        ->and(access()->seesEverything($admin))->toBeTrue()
        ->and(access()->canBrowse($folder, $plain))->toBeFalse()
        ->and(access()->canAdminister($folder, $plain))->toBeFalse()
        ->and(MediaAsset::query()->visibleTo($admin)->pluck('id')->all())->toBe([$asset->id])
        ->and(MediaFolder::query()->visibleTo($admin)->count())->toBe(2);
});

it('inherits a restriction down the tree and never lets a child widen it', function (): void {
    $member = W::user('Member');
    $outsider = W::user('Outsider');
    $top = W::restricted('Confidential', FolderAccessMode::Members);
    W::member($top, $member);
    $child = W::folder('Inherits', $top);
    $grandchild = W::folder('Deeper', $child);
    $shared = app(MediaFolderService::class)->setAccess(W::folder('Declared shared', $child), FolderAccessMode::Shared, null, '1');
    $asset = W::asset('deep-file', $grandchild);
    $sharedAsset = W::asset('declared-shared-file', $shared);

    foreach ([$child, $grandchild, $shared, $asset, $sharedAsset] as $target) {
        expect(access()->canBrowse($target, $outsider))->toBeFalse()
            ->and(access()->canBrowse($target, $member))->toBeTrue()
            ->and($target->isRestricted())->toBeTrue();
    }

    // The path of a visible folder never names a hidden one: nothing of the tree is listed to an outsider.
    expect(access()->listedFolderIds($outsider))->toBe([])
        ->and(access()->listedFolderIds($member))->toHaveCount(4)
        ->and(MediaAsset::query()->visibleTo($outsider)->count())->toBe(0);
});

it('lets the owner of a sub-folder inside a members folder narrow it, not escape it', function (): void {
    $member = W::user('Member');
    $top = W::restricted('Group', FolderAccessMode::Members);
    W::member($top, $member, 'manage');
    $mine = W::folder('Mine', $top, $member);

    expect(access()->canAdminister($mine, $member))->toBeTrue()
        ->and(access()->canAdminister($top, $member))->toBeFalse();

    app(MediaFolderService::class)->setAccess($mine, FolderAccessMode::Shared, null, (string) $member->id);

    // "Shared" under a members folder is still members-only.
    expect(access()->canBrowse($mine, W::user('Stranger')))->toBeFalse();
});

it('reads an unknown access value as members-only, and an unknown folder as closed', function (): void {
    $folder = W::folder('Odd');
    DB::table('lib_folders')->where('id', $folder->id)->update(['access' => 'something-new']);
    $stranger = W::user('Stranger');

    expect($folder->refresh()->accessMode())->toBe(FolderAccessMode::Members)
        ->and(access()->canBrowse($folder, $stranger))->toBeFalse()
        ->and(access()->canBrowse((new MediaFolder)->forceFill(['id' => 999999]), $stranger))->toBeFalse()
        ->and(access()->isRestricted((new MediaFolder)->forceFill(['id' => 999999])))->toBeTrue();
});

it('lets only the owner or manage-all administer a folder, and only if they could manage it', function (): void {
    $owner = W::user('Owner');
    $member = W::user('Member');
    $folder = W::restricted('Mine', FolderAccessMode::Members, $owner);
    W::member($folder, $member, 'manage');

    expect(access()->canAdminister($folder, $owner))->toBeTrue()
        ->and(access()->canAdminister($folder, $member))->toBeFalse();

    $shared = W::folder('Shared one', null, $owner);

    // The owner of a shared folder who has lost media.manage cannot change it either.
    expect(access()->canAdminister($shared, $owner))->toBeFalse();

    Gate::define('media.manage', static fn (): bool => true);

    expect(access()->canAdminister($shared, $owner))->toBeTrue()
        ->and(access()->canAdminister($shared, $member))->toBeFalse();
});

// ---------------------------------------------------------------- passwords

it('hides a password folder\'s contents until it is unlocked, but lists the folder itself', function (): void {
    $user = W::user('Reader');
    $folder = W::restricted('Board papers', FolderAccessMode::Password, password: 'open sesame');
    $child = W::folder('Minutes', $folder);
    $asset = W::asset('minutes-2025', $child);
    $this->actingAs($user);

    expect(access()->canBrowse($folder))->toBeFalse()
        ->and(access()->isListed($folder))->toBeTrue()
        ->and(access()->isLocked($folder))->toBeTrue()
        // what is inside is not listed, and neither is anything below
        ->and(access()->isListed($child))->toBeFalse()
        ->and(access()->canBrowse($asset))->toBeFalse()
        ->and(access()->blockingFolder($child)?->is($folder))->toBeTrue()
        ->and(MediaFolder::query()->visibleTo()->pluck('id')->all())->toBe([$folder->id])
        ->and(MediaAsset::query()->visibleTo()->count())->toBe(0)
        ->and(access()->lockedFolderIds())->toBe([$folder->id]);

    expect(access()->unlock($child, 'open sesame'))->toBeTrue()
        ->and(access()->isUnlocked($folder))->toBeTrue()
        ->and(access()->isUnlocked($child))->toBeTrue()
        ->and(access()->canBrowse($asset))->toBeTrue()
        ->and(access()->isLocked($folder))->toBeFalse()
        ->and(MediaAsset::query()->visibleTo()->pluck('id')->all())->toBe([$asset->id])
        ->and(MediaFolder::query()->visibleTo()->count())->toBe(2);
});

it('keeps the password gate in front of media.manage: unlocked is not enough to manage', function (): void {
    $user = W::user('Reader');
    $folder = W::restricted('Locked', FolderAccessMode::Password, password: 'open sesame');
    $this->actingAs($user);
    access()->unlock($folder, 'open sesame');

    expect(access()->canBrowse($folder))->toBeTrue()
        ->and(access()->canManage($folder))->toBeFalse();

    Gate::define('media.manage', static fn (): bool => true);

    expect(access()->canManage($folder))->toBeTrue();
});

it('lets the owner and manage-all in without unlocking, and nobody else', function (): void {
    $owner = W::user('Owner');
    $admin = W::user('Admin');
    $other = W::user('Other');
    $folder = W::restricted('Secret', FolderAccessMode::Password, $owner, 'open sesame');
    Gate::define('media.manage-all', static fn ($user): bool => $user->name === 'Admin');

    expect(access()->canBrowse($folder, $owner))->toBeTrue()
        ->and(access()->canBrowse($folder, $admin))->toBeTrue()
        ->and(access()->canBrowse($folder, $other))->toBeFalse();
});

it('refuses a wrong password, records the failure, and never puts the password in the event', function (): void {
    $events = collectEvents();
    $folder = W::restricted('Vault', FolderAccessMode::Password, password: 'open sesame');
    $user = W::user('Guesser');
    $this->actingAs($user);

    expect(access()->unlock($folder, 'not-the-password'))->toBeFalse()
        ->and(access()->isUnlocked($folder))->toBeFalse();

    $failures = array_values(array_filter($events->getArrayCopy(), static fn (MediaEvent $e): bool => $e instanceof FolderUnlockFailed));

    expect($failures)->toHaveCount(1)
        ->and($failures[0]->name())->toBe('media.folder.unlock_failed')
        ->and($failures[0]->payload())->toBe(['folder_id' => $folder->id, 'attempts' => 1])
        ->and($failures[0]->actorId())->toBe((string) $user->id)
        ->and(json_encode($failures[0]->payload()))->not->toContain('not-the-password');
});

it('throttles the sixth attempt in fifteen minutes, even with the right password, and records it', function (): void {
    $events = collectEvents();
    $folder = W::restricted('Vault', FolderAccessMode::Password, password: 'open sesame');
    $this->actingAs(W::user('Guesser'));

    foreach (range(1, 5) as $attempt) {
        expect(access()->unlock($folder, 'wrong '.$attempt))->toBeFalse();
    }

    expect(access()->isThrottled($folder))->toBeTrue()
        ->and(access()->retryAfter($folder))->toBeGreaterThan(0)
        ->and(access()->unlock($folder, 'open sesame'))->toBeFalse()
        ->and(access()->isUnlocked($folder))->toBeFalse();

    $failures = array_values(array_filter($events->getArrayCopy(), static fn (MediaEvent $e): bool => $e instanceof FolderUnlockFailed));

    expect($failures)->toHaveCount(6)
        ->and(array_map(static fn (FolderUnlockFailed $e): int => $e->attempts, $failures))->toBe([1, 2, 3, 4, 5, 5]);

    $this->travel(16)->minutes();

    expect(access()->isThrottled($folder))->toBeFalse()
        ->and(access()->unlock($folder, 'open sesame'))->toBeTrue();
});

it('throttles per user and per folder, and a success clears the count', function (): void {
    $one = W::restricted('One', FolderAccessMode::Password, password: 'open sesame');
    $two = W::restricted('Two', FolderAccessMode::Password, password: 'open sesame');
    $alice = W::user('Alice');
    $bob = W::user('Bob');

    foreach (range(1, 5) as $attempt) {
        access()->unlock($one, 'wrong', $alice);
    }

    expect(access()->isThrottled($one, $alice))->toBeTrue()
        ->and(access()->isThrottled($one, $bob))->toBeFalse()
        ->and(access()->isThrottled($two, $alice))->toBeFalse()
        ->and(access()->unlock($two, 'open sesame', $alice))->toBeTrue();

    foreach (range(1, 4) as $attempt) {
        access()->unlock($one, 'wrong', $bob);
    }

    access()->unlock($one, 'open sesame', $bob);
    access()->unlock($one, 'wrong', $bob);

    // four failures, a success, one more failure: the count restarted at the success
    expect(access()->isThrottled($one, $bob))->toBeFalse();
});

it('keeps an unlock for thirty minutes and then closes it', function (): void {
    $folder = W::restricted('Timed', FolderAccessMode::Password, password: 'open sesame');
    $this->actingAs(W::user('Reader'));

    expect(access()->unlock($folder, 'open sesame'))->toBeTrue();

    $this->travel(29)->minutes();
    expect(access()->canBrowse($folder))->toBeTrue();

    $this->travel(2)->minutes();
    expect(access()->canBrowse($folder))->toBeFalse()
        ->and(access()->isUnlocked($folder))->toBeFalse();
});

it('honours a configured unlock lifetime', function (): void {
    config()->set('media-library.folders.unlock_minutes', 5);
    $folder = W::restricted('Short', FolderAccessMode::Password, password: 'open sesame');
    $this->actingAs(W::user('Reader'));
    access()->unlock($folder, 'open sesame');

    $this->travel(6)->minutes();

    expect(access()->canBrowse($folder))->toBeFalse();
});

it('relocks everyone when the password changes, and when the mode changes and changes back', function (): void {
    $service = app(MediaFolderService::class);
    $folder = W::restricted('Relock', FolderAccessMode::Password, password: 'open sesame');
    $this->actingAs(W::user('Reader'));
    access()->unlock($folder, 'open sesame');

    expect(access()->canBrowse($folder))->toBeTrue();

    $service->setAccess($folder, FolderAccessMode::Password, 'a brand new password', '1');

    expect(access()->canBrowse($folder))->toBeFalse()
        ->and(access()->unlock($folder, 'open sesame'))->toBeFalse()
        ->and(access()->unlock($folder, 'a brand new password'))->toBeTrue();

    // away and back with the very same words: a fresh hash, so the old session unlock is dead
    $service->setAccess($folder, FolderAccessMode::Shared, null, '1');
    $service->setAccess($folder, FolderAccessMode::Password, 'a brand new password', '1');

    expect(access()->canBrowse($folder))->toBeFalse();
});

it('forgets an unlock on lock()', function (): void {
    $folder = W::restricted('Lockable', FolderAccessMode::Password, password: 'open sesame');
    $this->actingAs(W::user('Reader'));
    access()->unlock($folder, 'open sesame');
    access()->lock($folder);

    expect(access()->canBrowse($folder))->toBeFalse();
});

it('stores the unlock server side in the session, and never the password or its hash', function (): void {
    $folder = W::restricted('Stored', FolderAccessMode::Password, password: 'open sesame');
    $this->actingAs(W::user('Reader'));
    access()->unlock($folder, 'open sesame');

    $entry = session()->get(FolderAccess::sessionKey($folder->uuid));
    $hash = (string) DB::table('lib_folders')->where('id', $folder->id)->value('password_hash');

    expect($entry)->toBeArray()
        ->and($entry['until'])->toBeInt()->toBeGreaterThan(now()->getTimestamp())
        ->and(json_encode(session()->all()))->not->toContain('open sesame')->not->toContain($hash)
        ->and($hash)->not->toBe('open sesame')
        ->and(Hash::check('open sesame', $hash))->toBeTrue();
});

it('rejects a forged, expired or stale session entry', function (): void {
    $folder = W::restricted('Forged', FolderAccessMode::Password, password: 'open sesame');
    $this->actingAs(W::user('Reader'));
    $key = FolderAccess::sessionKey($folder->uuid);

    foreach ([
        true,
        now()->addHour()->getTimestamp(),
        ['until' => now()->addHour()->getTimestamp()],
        ['until' => now()->addHour()->getTimestamp(), 'fp' => 'guess'],
        ['until' => now()->subMinute()->getTimestamp(), 'fp' => 'guess'],
        ['until' => 'tomorrow', 'fp' => 'guess'],
    ] as $forged) {
        session()->put($key, $forged);
        expect(access()->canBrowse($folder))->toBeFalse();
    }
});

it('does not let a user unlock what they could not reach anyway', function (): void {
    $outer = W::restricted('Members only', FolderAccessMode::Members);
    $inner = W::restricted('Password inside', FolderAccessMode::Password, parent: $outer, password: 'open sesame');
    $stranger = W::user('Stranger');
    $events = collectEvents();

    // The right password, but the stranger cannot even see the folder around it.
    expect(access()->unlock($inner, 'open sesame', $stranger))->toBeFalse()
        ->and(session()->has(FolderAccess::sessionKey($inner->uuid)))->toBeFalse()
        ->and($events->getArrayCopy())->toHaveCount(0);

    $plain = W::folder('Not protected');

    expect(access()->unlock($plain, 'anything', $stranger))->toBeFalse()
        ->and(access()->isUnlocked($plain))->toBeFalse();
});

it('needs both passwords when password folders are nested', function (): void {
    $outer = W::restricted('Outer', FolderAccessMode::Password, password: 'outer password');
    $inner = W::restricted('Inner', FolderAccessMode::Password, parent: $outer, password: 'inner password');
    $asset = W::asset('nested', $inner);
    $this->actingAs(W::user('Reader'));

    // The inner folder cannot be unlocked from outside the outer one.
    expect(access()->unlock($inner, 'inner password'))->toBeFalse();

    access()->unlock($outer, 'outer password');

    expect(access()->canBrowse($asset))->toBeFalse()
        ->and(access()->isLocked($inner))->toBeTrue()
        ->and(access()->blockingFolder($inner)?->is($inner))->toBeTrue()
        ->and(access()->unlock($inner, 'inner password'))->toBeTrue()
        ->and(access()->canBrowse($asset))->toBeTrue();
});

// ---------------------------------------------------------------- the service

it('stores only a hash, and requires a long enough password', function (): void {
    $service = app(MediaFolderService::class);
    $folder = W::folder('Protected');

    expect(fn () => $service->setAccess($folder, FolderAccessMode::Password, 'short', '1'))->toThrow(InvalidFolderAccess::class)
        ->and(fn () => $service->setAccess($folder, FolderAccessMode::Password, null, '1'))->toThrow(InvalidFolderAccess::class)
        ->and(fn () => $service->setAccess($folder, FolderAccessMode::Members, 'a password nobody wanted', '1'))->toThrow(InvalidFolderAccess::class)
        ->and($folder->refresh()->access)->toBe('inherit');

    $service->setAccess($folder, FolderAccessMode::Password, 'twelve chars!', '1');
    $hash = (string) DB::table('lib_folders')->where('id', $folder->id)->value('password_hash');

    expect($hash)->not->toBe('twelve chars!')
        ->and(Hash::check('twelve chars!', $hash))->toBeTrue()
        ->and($folder->refresh()->toArray())->not->toHaveKey('password_hash')
        ->and(json_encode($folder))->not->toContain($hash);
});

it('keeps the current password when the mode is saved again without one, and drops it on leaving the mode', function (): void {
    $service = app(MediaFolderService::class);
    $folder = W::restricted('Keeps', FolderAccessMode::Password, password: 'open sesame');
    $before = (string) DB::table('lib_folders')->where('id', $folder->id)->value('password_hash');
    $events = collectEvents();

    $service->setAccess($folder, FolderAccessMode::Password, null, '1');

    expect((string) DB::table('lib_folders')->where('id', $folder->id)->value('password_hash'))->toBe($before)
        ->and($events->getArrayCopy())->toHaveCount(0);

    $service->setAccess($folder, FolderAccessMode::Members, null, '1');

    expect(DB::table('lib_folders')->where('id', $folder->id)->value('password_hash'))->toBeNull();
});

it('dispatches FolderAccessChanged with from, to and the member count, and nothing secret', function (): void {
    $service = app(MediaFolderService::class);
    $folder = W::folder('Audited');
    $service->addMember($folder, W::user('One')->id, 'view', '1');
    $service->addMember($folder, W::user('Two')->id, 'manage', '1');
    $events = collectEvents();

    $service->setAccess($folder, FolderAccessMode::Password, 'twelve chars!', '7');
    $service->setAccess($folder, FolderAccessMode::Password, 'another one!!', '7');

    $changes = array_values(array_filter($events->getArrayCopy(), static fn (MediaEvent $e): bool => $e instanceof FolderAccessChanged));
    $hash = (string) DB::table('lib_folders')->where('id', $folder->id)->value('password_hash');

    expect($changes)->toHaveCount(2)
        ->and($changes[0]->name())->toBe('media.folder.access_changed')
        ->and($changes[0]->payload())->toBe(['folder_id' => $folder->id, 'from' => 'inherit', 'to' => 'password', 'members_count' => 2])
        ->and($changes[0]->actorId())->toBe('7')
        // a password change inside the mode is an event too
        ->and($changes[1]->payload()['from'])->toBe('password')
        ->and($changes[1]->payload()['to'])->toBe('password')
        ->and(json_encode(array_map(static fn (MediaEvent $e): array => $e->payload(), $events->getArrayCopy())))
        ->not->toContain('twelve chars!')->not->toContain('another one!!')->not->toContain($hash);
});

it('adds, re-roles and removes members with events, and validates them', function (): void {
    $service = app(MediaFolderService::class);
    $folder = W::folder('Members');
    $user = W::user('Member');
    $events = collectEvents();

    $service->addMember($folder, $user->id, 'view', '9');
    $service->addMember($folder, (string) $user->id, 'view', '9');
    $service->addMember($folder, $user->id, 'manage', '9');
    $service->removeMember($folder, $user->id, '9');
    $service->removeMember($folder, $user->id, '9');

    $changes = array_values(array_filter($events->getArrayCopy(), static fn (MediaEvent $e): bool => $e instanceof FolderMembersChanged));

    expect(array_map(static fn (FolderMembersChanged $e): array => [$e->payload()['change'], $e->payload()['role'], $e->payload()['members_count']], $changes))
        ->toBe([['added', 'view', 1], ['role_changed', 'manage', 1], ['removed', null, 0]])
        ->and($changes[0]->name())->toBe('media.folder.members_changed')
        ->and($folder->members()->count())->toBe(0)
        ->and(fn () => $service->addMember($folder, $user->id, 'owner', '9'))->toThrow(InvalidFolderAccess::class)
        ->and(fn () => $service->addMember($folder, 'jane', 'view', '9'))->toThrow(InvalidFolderAccess::class)
        ->and(fn () => $service->addMember($folder, 0, 'view', '9'))->toThrow(InvalidFolderAccess::class)
        ->and(fn () => $service->removeMember($folder, 'jane', '9'))->toThrow(InvalidFolderAccess::class);
});

it('takes effect at once when membership is removed, in the same request', function (): void {
    $service = app(MediaFolderService::class);
    $folder = W::restricted('Temporary', FolderAccessMode::Members);
    $user = W::user('Visitor');
    $service->addMember($folder, $user->id, 'view', '1');

    expect(access()->canBrowse($folder, $user))->toBeTrue();

    $service->removeMember($folder, $user->id, '1');

    expect(access()->canBrowse($folder, $user))->toBeFalse();
});

it('drops its folder cache when a folder is written, so a new restriction applies immediately', function (): void {
    $folder = W::folder('Cached');
    $user = W::user('Visitor');

    expect(access()->canBrowse($folder, $user))->toBeTrue();

    $folder->forceFill(['access' => 'members'])->save();

    expect(access()->canBrowse($folder, $user))->toBeFalse();

    $folder->delete();
    expect(access()->canBrowse($folder, $user))->toBeFalse();
});

it('removes the members with the folder', function (): void {
    $folder = W::folder('Gone');
    W::member($folder, W::user('Member'));
    app(MediaFolderService::class)->delete($folder, '1');

    expect(DB::table('lib_folder_members')->count())->toBe(0);
});
