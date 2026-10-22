<?php

declare(strict_types=1);

use Banyan\FilamentMediaLibrary\Access\FolderAccess;
use Banyan\FilamentMediaLibrary\Admin\MediaAssetPage;
use Banyan\FilamentMediaLibrary\Admin\MediaLibraryPage;
use Banyan\FilamentMediaLibrary\Admin\Support\FolderChoices;
use Banyan\FilamentMediaLibrary\Enums\FolderAccessMode;
use Banyan\FilamentMediaLibrary\Folders\MediaFolderService;
use Banyan\FilamentMediaLibrary\Governance\MediaGovernanceService;
use Banyan\FilamentMediaLibrary\Models\MediaAsset;
use Banyan\FilamentMediaLibrary\Models\MediaFolder;
use Banyan\FilamentMediaLibrary\Tests\Support\AccessWorld as W;
use Banyan\FilamentMediaLibrary\Tests\Support\Images;
use Banyan\FilamentMediaLibrary\Tests\Support\User;
use Banyan\FilamentMediaLibrary\Usage\UsageRecorder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

covers(MediaLibraryPage::class, MediaAssetPage::class, FolderChoices::class);

/**
 * A members folder with a nested folder, a file in each, a tag, an expired licence and a
 * password folder next to it. Everything has a name nobody would type by accident.
 *
 * @return array{owner: User, folder: MediaFolder, nested: MediaFolder, asset: MediaAsset, nestedAsset: MediaAsset, locked: MediaFolder, lockedAsset: MediaAsset}
 */
function secretLibrary(): array
{
    $owner = W::user('Owner');
    $folder = W::restricted('Zebra Payroll Vault', FolderAccessMode::Members, $owner);
    $nested = W::folder('Quokka Severance Drafts', $folder, $owner);
    $asset = W::asset('narwhal-payslips', $folder);
    $nestedAsset = W::asset('pangolin-severance', $nested);
    app(MediaGovernanceService::class)->syncTags($asset, ['okapi-redundancy-tag'], '1');
    $asset->forceFill(['license_expires_at' => now()->subDay()])->save();
    $locked = W::restricted('Ibex Board Papers', FolderAccessMode::Password, $owner, 'open sesame');
    $lockedAsset = W::asset('gazelle-minutes', $locked);

    return compact('owner', 'folder', 'nested', 'asset', 'nestedAsset', 'locked', 'lockedAsset');
}

/** @return list<string> every string that must not reach an outsider's page or payload */
function secretsOf(array $world): array
{
    return [
        $world['folder']->name, $world['folder']->uuid,
        $world['nested']->name, $world['nested']->uuid,
        'narwhal-payslips', 'pangolin-severance', $world['asset']->uuid, $world['nestedAsset']->uuid,
        'okapi-redundancy-tag',
    ];
}

/** The page's HTML and its whole Livewire snapshot, as one string to search. */
function everythingSent(Testable $page): string
{
    return $page->html().json_encode($page->snapshot);
}

function expectNoTrace(string $haystack, array $needles): void
{
    foreach ($needles as $needle) {
        expect($haystack)->not->toContain($needle);
    }
}

// ---------------------------------------------------------------- the library page, for an outsider

it('leaves no trace of a members folder in the page, the payload, the counts or the banner for a non-member', function (): void {
    $world = secretLibrary();
    $outsider = W::user('Outsider');
    $visible = W::asset('plain-public-file');

    $page = Livewire::actingAs($outsider)->test(MediaLibraryPage::class);

    expectNoTrace(everythingSent($page), secretsOf($world));
    $page->assertSee('plain-public-file.png')->assertSee('1 asset');

    // The password folder is shown (to be unlocked); what is inside is not.
    $page->assertSee('Ibex Board Papers')->assertSee('Locked');
    expectNoTrace(everythingSent($page), ['gazelle-minutes', $world['lockedAsset']->uuid]);

    // The expiry banner counts only what the outsider can see: the expired asset is in the hidden folder.
    expect($page->instance()->expirySummary()->expired)->toBe(0)
        ->and(array_map(static fn (MediaAsset $a): int => $a->id, $page->instance()->assets()))->toBe([$visible->id])
        ->and($page->instance()->tagOptions())->toBe([]);
});

it('hides the folder from search, the whole-library toggle and every filter', function (): void {
    $world = secretLibrary();

    foreach (['narwhal', 'pangolin', 'payslips', 'Zebra'] as $term) {
        $page = Livewire::actingAs(W::user('Outsider'))->test(MediaLibraryPage::class)
            ->set('search', $term)
            ->set('wholeLibrary', true);

        expect($page->instance()->assets())->toBe([])
            ->and($page->instance()->totalAssets())->toBe(0);
        expectNoTrace(everythingSent($page), secretsOf($world));
    }

    $page = Livewire::actingAs(W::user('Outsider'))->test(MediaLibraryPage::class)
        ->set('expiredOnly', true)
        ->call('showExpiredAssets');

    expect($page->instance()->totalAssets())->toBe(0);
    expectNoTrace(everythingSent($page), secretsOf($world));

    // by tag id too: knowing a tag id is not seeing the asset
    $tagId = (int) $world['asset']->tags()->value('lib_tags.id');
    $page = Livewire::actingAs(W::user('Outsider'))->test(MediaLibraryPage::class)->set('tagId', $tagId);

    expect($page->instance()->totalAssets())->toBe(0);
});

it('opens the root when an outsider asks for the folder by uuid, in the URL or by calling openFolder', function (): void {
    $world = secretLibrary();
    $outsider = W::user('Outsider');

    $page = Livewire::actingAs($outsider)->withQueryParams(['folder' => $world['folder']->uuid])->test(MediaLibraryPage::class);

    expect($page->instance()->currentFolder())->toBeNull();
    $page->assertDontSee('This folder is empty');
    expectNoTrace($page->html(), secretsOf($world));

    $page = Livewire::actingAs($outsider)->test(MediaLibraryPage::class)
        ->call('openFolder', $world['nested']->uuid)
        ->call('toggleFolder', $world['folder']->uuid);

    $page->assertSet('currentFolder', null);
    expect($page->get('expanded'))->toBe([]);
    expectNoTrace(everythingSent($page), secretsOf($world));
});

it('shows the folder, its files and the nested folder to a member, with the counts they can see', function (): void {
    $world = secretLibrary();
    $member = W::user('Member');
    W::member($world['folder'], $member);

    $page = Livewire::actingAs($member)->test(MediaLibraryPage::class)
        ->assertSee('Zebra Payroll Vault')
        ->assertSee('Members only')
        // the folder holds one file and one sub-folder
        ->assertSee('2 items')
        ->call('openFolder', $world['folder']->uuid)
        ->assertSee('narwhal-payslips.png')
        ->assertSee('Quokka Severance Drafts');

    expect($page->instance()->tagOptions())->toBe([(int) $world['asset']->tags()->value('lib_tags.id') => 'okapi-redundancy-tag'])
        ->and($page->instance()->expirySummary()->expired)->toBe(1);
});

it('does not count what a user cannot see in a folder tile', function (): void {
    $world = secretLibrary();
    $owner = $world['owner'];
    $member = W::user('Member');
    $open = W::folder('Open Plaza', null, $owner);
    W::asset('open-one', $open);
    // a hidden sub-folder and a hidden file inside an otherwise visible folder
    $hidden = W::restricted('Hidden In Plaza', FolderAccessMode::Members, $owner, parent: $open);
    W::asset('hidden-in-plaza', $hidden);

    $page = Livewire::actingAs($member)->test(MediaLibraryPage::class);
    $tiles = collect($page->instance()->subfolders())->keyBy('name');

    expect($tiles['Open Plaza']->assets_count)->toBe(1)
        ->and($tiles['Open Plaza']->children_count)->toBe(0)
        ->and($page->instance()->itemCount($tiles['Open Plaza']))->toBe(1);
    expectNoTrace(everythingSent($page), ['Hidden In Plaza', $hidden->uuid, 'hidden-in-plaza']);

    $page->call('openFolder', $open->uuid);
    expectNoTrace(everythingSent($page), ['Hidden In Plaza', $hidden->uuid, 'hidden-in-plaza']);
});

it('shows media.manage-all everything', function (): void {
    $world = secretLibrary();
    $admin = W::user('Admin');
    Gate::define('media.manage-all', static fn ($user): bool => $user->name === 'Admin');

    $page = Livewire::actingAs($admin)->test(MediaLibraryPage::class)
        ->assertSee('Zebra Payroll Vault')
        ->call('openFolder', $world['folder']->uuid)
        ->assertSee('narwhal-payslips.png');

    expect($page->instance()->canManage())->toBeTrue();
});

// ---------------------------------------------------------------- passwords on the page

it('shows an unlock form instead of the contents, and the contents after the right password', function (): void {
    $world = secretLibrary();
    $reader = W::user('Reader');

    $page = Livewire::actingAs($reader)->test(MediaLibraryPage::class)
        ->call('openFolder', $world['locked']->uuid)
        ->assertSee('This folder is password protected')
        ->assertDontSee('gazelle-minutes.png')
        ->assertDontSee('This folder is empty');

    expect($page->instance()->lockedFolder()?->is($world['locked']))->toBeTrue();

    $page->callAction('unlockFolder', ['password' => 'wrong password'])
        ->assertSee('This folder is password protected')
        ->assertDontSee('gazelle-minutes.png');

    $page->callAction('unlockFolder', ['password' => 'open sesame'])
        ->assertDontSee('This folder is password protected')
        ->assertSee('gazelle-minutes.png');

    expect($page->instance()->lockedFolder())->toBeNull();
});

it('tells a user who has used up their attempts, and refuses even the right password', function (): void {
    $world = secretLibrary();
    $reader = W::user('Reader');
    $page = Livewire::actingAs($reader)->test(MediaLibraryPage::class)->call('openFolder', $world['locked']->uuid);

    foreach (range(1, 5) as $attempt) {
        $page->callAction('unlockFolder', ['password' => 'wrong '.$attempt]);
    }

    $page->callAction('unlockFolder', ['password' => 'open sesame'])
        ->assertNotified(__('media-library::media.access.throttled', ['minutes' => 15]))
        ->assertSee('This folder is password protected')
        ->assertDontSee('gazelle-minutes.png');

    expect(app(FolderAccess::class)->canBrowse($world['locked']))->toBeFalse();
});

it('never writes the password into the page or the payload', function (): void {
    $world = secretLibrary();
    $page = Livewire::actingAs(W::user('Reader'))->test(MediaLibraryPage::class)
        ->call('openFolder', $world['locked']->uuid)
        ->callAction('unlockFolder', ['password' => 'open sesame']);

    $hash = (string) DB::table('lib_folders')->where('id', $world['locked']->id)->value('password_hash');

    expectNoTrace(everythingSent($page), ['open sesame', $hash]);
});

it('relocks an open page when the password is changed under it', function (): void {
    $world = secretLibrary();
    $page = Livewire::actingAs(W::user('Reader'))->test(MediaLibraryPage::class)
        ->call('openFolder', $world['locked']->uuid)
        ->callAction('unlockFolder', ['password' => 'open sesame'])
        ->assertSee('gazelle-minutes.png');

    app(MediaFolderService::class)->setAccess($world['locked'], FolderAccessMode::Password, 'a new password', '1');

    $page->call('$refresh')->assertDontSee('gazelle-minutes.png')->assertSee('This folder is password protected');
});

it('lets the owner into their own password folder without unlocking', function (): void {
    $world = secretLibrary();

    Livewire::actingAs($world['owner'])->test(MediaLibraryPage::class)
        ->call('openFolder', $world['locked']->uuid)
        ->assertSee('gazelle-minutes.png')
        ->assertDontSee('This folder is password protected');
});

// ---------------------------------------------------------------- managing

it('lets a view member browse but gives them no action, and refuses the actions called directly', function (): void {
    $world = secretLibrary();
    $viewer = W::user('Viewer');
    W::member($world['folder'], $viewer, 'view');

    $page = Livewire::actingAs($viewer)->test(MediaLibraryPage::class)
        ->call('openFolder', $world['folder']->uuid)
        ->assertSee('narwhal-payslips.png')
        ->assertActionHidden('upload')
        ->assertActionHidden('newFolder')
        ->assertActionHidden('accessFolder')
        ->assertDontSeeHtml('wire:model.live="selected"');

    // Forged calls: nothing happens.
    $page->call('mountAction', 'deleteAsset', ['asset' => $world['asset']->id])->call('callMountedAction');
    $page->call('mountAction', 'newFolder')->call('callMountedAction', ['name' => 'Smuggled']);
    $page->set('selected', [$world['asset']->id])->call('mountAction', 'moveSelected')->call('callMountedAction', ['destination' => null]);

    expect(MediaAsset::query()->whereKey($world['asset']->id)->exists())->toBeTrue()
        ->and($world['asset']->refresh()->folder_id)->toBe($world['folder']->id)
        ->and(MediaFolder::query()->where('name', 'Smuggled')->exists())->toBeFalse();
});

it('lets a manage member upload, make folders and delete in the folder, without media.manage', function (): void {
    $world = secretLibrary();
    $manager = W::user('Manager');
    W::member($world['folder'], $manager, 'manage');

    $page = Livewire::actingAs($manager)->test(MediaLibraryPage::class)
        ->call('openFolder', $world['folder']->uuid)
        ->callAction('upload', [
            'file' => UploadedFile::fake()->createWithContent('uploaded.png', Images::splitPng()),
            'alt_text' => 'Uploaded by a member',
        ])
        ->assertHasNoActionErrors()
        ->callAction('newFolder', ['name' => 'Made By Member'])
        ->assertHasNoActionErrors();

    $uploaded = MediaAsset::query()->where('filename', 'uploaded.png')->sole();

    expect($uploaded->folder_id)->toBe($world['folder']->id)
        ->and(MediaFolder::query()->where('name', 'Made By Member')->sole()->parent_id)->toBe($world['folder']->id);

    $page->callAction('deleteAsset', arguments: ['asset' => $uploaded->id]);

    expect(MediaAsset::query()->whereKey($uploaded->id)->exists())->toBeFalse();

    // ... but not at the root, which still follows media.manage
    Livewire::actingAs($manager)->test(MediaLibraryPage::class)->assertActionHidden('upload')->assertActionHidden('newFolder');
});

it('refuses an upload into a folder the user cannot manage, even when the form is submitted anyway', function (): void {
    $world = secretLibrary();
    $viewer = W::user('Viewer');
    W::member($world['folder'], $viewer, 'view');
    Gate::define('media.manage', static fn ($user): bool => $user->name === 'Viewer');

    // media.manage lets them upload at the root, but the open folder is members-only with a view role.
    $page = Livewire::actingAs($viewer)->test(MediaLibraryPage::class)
        ->call('openFolder', $world['folder']->uuid)
        ->assertActionHidden('upload');

    $page->call('mountAction', 'upload')->call('callMountedAction');

    expect(MediaAsset::query()->where('filename', 'not-uploaded.png')->exists())->toBeFalse();
});

it('drops hidden and unmanageable ids from a bulk move instead of moving them', function (): void {
    $world = secretLibrary();
    $editor = W::user('Editor');
    Gate::define('media.manage', static fn (): bool => true);
    $mine = W::asset('mine-to-move');
    $target = W::folder('Target Folder', null, $editor);

    Livewire::actingAs($editor)->test(MediaLibraryPage::class)
        // ids the editor was never shown, ticked by hand
        ->set('selected', [$mine->id, $world['asset']->id, $world['nestedAsset']->id, $world['lockedAsset']->id])
        ->callAction('moveSelected', ['destination' => $target->uuid]);

    expect($mine->refresh()->folder_id)->toBe($target->id)
        ->and($world['asset']->refresh()->folder_id)->toBe($world['folder']->id)
        ->and($world['nestedAsset']->refresh()->folder_id)->toBe($world['nested']->id)
        ->and($world['lockedAsset']->refresh()->folder_id)->toBe($world['locked']->id);
});

it('offers only folders the user may put files in as move targets, and refuses others even if named', function (): void {
    $world = secretLibrary();
    $editor = W::user('Editor');
    Gate::define('media.manage', static fn (): bool => true);
    $open = W::folder('Open Target', null, $editor);
    $mine = W::asset('mine-to-move');

    $this->actingAs($editor);
    $options = FolderChoices::options();
    $viewable = FolderChoices::options(manageable: false);

    expect(array_values($options))->toBe(['Open Target'])
        ->and(array_keys($options))->toBe([$open->uuid])
        // the password folder is listed, so it is in the "visible" list, but a locked folder is not a target
        ->and(array_values($viewable))->toContain('Ibex Board Papers')->not->toContain('Zebra Payroll Vault');
    expectNoTrace(json_encode([$options, $viewable]), secretsOf($world));

    // naming the hidden folder by uuid gets the same refusal as a folder that does not exist
    foreach ([$world['folder']->uuid, $world['nested']->uuid, $world['locked']->uuid, '00000000-0000-4000-8000-000000000000'] as $uuid) {
        Livewire::actingAs($editor)->test(MediaLibraryPage::class)
            ->set('selected', [$mine->id])
            ->callAction('moveSelected', ['destination' => $uuid]);

        expect($mine->refresh()->folder_id)->toBeNull();
    }
});

it('warns, and needs a confirmation, before a used file goes into a restricted folder', function (): void {
    $world = secretLibrary();
    Gate::define('media.manage', static fn (): bool => true);
    $owner = $world['owner'];
    $used = W::asset('used-on-a-page');
    $unused = W::asset('not-used');
    app(UsageRecorder::class)->sync(W::user('Page'), 'hero', [$used->id]);

    Livewire::actingAs($owner)->test(MediaLibraryPage::class)
        ->set('selected', [$used->id, $unused->id])
        ->callAction('moveSelected', ['destination' => $world['folder']->uuid])
        ->assertHasActionErrors(['confirm_usage']);

    // Refused without the confirmation: nothing moved at all.
    expect($used->refresh()->folder_id)->toBeNull()
        ->and($unused->refresh()->folder_id)->toBeNull();

    Livewire::actingAs($owner)->test(MediaLibraryPage::class)
        ->set('selected', [$used->id, $unused->id])
        ->callAction('moveSelected', ['destination' => $world['folder']->uuid, 'confirm_usage' => true]);

    expect($used->refresh()->folder_id)->toBe($world['folder']->id)
        ->and($unused->refresh()->folder_id)->toBe($world['folder']->id);

    // An unused file goes in without any fuss.
    $plain = W::asset('plain-unused');
    Livewire::actingAs($owner)->test(MediaLibraryPage::class)
        ->set('selected', [$plain->id])
        ->callAction('moveSelected', ['destination' => $world['folder']->uuid]);

    expect($plain->refresh()->folder_id)->toBe($world['folder']->id);
});

// ---------------------------------------------------------------- the access action

it('lets the owner set a password and members through the Access action, and nobody else', function (): void {
    $owner = W::user('Owner');
    $member = W::user('Member');
    $folder = W::folder('Governed', null, $owner);
    Gate::define('media.manage', static fn (): bool => true);

    Livewire::actingAs($member)->test(MediaLibraryPage::class)
        ->call('openFolder', $folder->uuid)
        ->assertActionHidden('accessFolder');

    Livewire::actingAs($owner)->test(MediaLibraryPage::class)
        ->call('openFolder', $folder->uuid)
        ->assertActionVisible('accessFolder')
        ->callAction('accessFolder', ['mode' => 'password', 'password' => 'short', 'password_confirmation' => 'short'])
        ->assertHasActionErrors(['password']);

    expect($folder->refresh()->access)->toBe('inherit');

    Livewire::actingAs($owner)->test(MediaLibraryPage::class)
        ->call('openFolder', $folder->uuid)
        ->callAction('accessFolder', ['mode' => 'password', 'password' => 'long enough pw', 'password_confirmation' => 'different pw'])
        ->assertHasActionErrors(['password']);

    Livewire::actingAs($owner)->test(MediaLibraryPage::class)
        ->call('openFolder', $folder->uuid)
        ->callAction('accessFolder', ['mode' => 'password', 'password' => 'long enough pw', 'password_confirmation' => 'long enough pw'])
        ->assertHasNoActionErrors();

    expect($folder->refresh()->access)->toBe('password')
        ->and(Hash::check('long enough pw', (string) DB::table('lib_folders')->where('id', $folder->id)->value('password_hash')))->toBeTrue();

    Livewire::actingAs($owner)->test(MediaLibraryPage::class)
        ->call('openFolder', $folder->uuid)
        ->callAction('accessFolder', ['mode' => 'members', 'members' => [['user_id' => $member->id, 'role' => 'manage']]])
        ->assertHasNoActionErrors();

    expect($folder->refresh()->access)->toBe('members')
        ->and(DB::table('lib_folder_members')->where('media_folder_id', $folder->id)->pluck('role', 'user_id')->all())->toBe([$member->id => 'manage'])
        ->and(DB::table('lib_folders')->where('id', $folder->id)->value('password_hash'))->toBeNull();

    // The non-owner, forging the action on a shared folder they can manage, changes nothing.
    $other = W::folder('Not Theirs', null, $owner);
    Livewire::actingAs($member)->test(MediaLibraryPage::class)
        ->call('openFolder', $other->uuid)
        ->call('mountAction', 'accessFolder')
        ->call('callMountedAction', ['mode' => 'members']);

    expect($other->refresh()->access)->toBe('inherit');
});

it('lets manage-all change the access of someone else\'s folder', function (): void {
    $folder = W::restricted('Someone Elses', FolderAccessMode::Members, W::user('Owner'));
    $admin = W::user('Admin');
    Gate::define('media.manage-all', static fn ($user): bool => $user->name === 'Admin');

    Livewire::actingAs($admin)->test(MediaLibraryPage::class)
        ->call('openFolder', $folder->uuid)
        ->assertActionVisible('accessFolder')
        ->callAction('accessFolder', ['mode' => 'shared'])
        ->assertHasNoActionErrors();

    expect($folder->refresh()->access)->toBe('shared');
});

it('does not prefill the password and keeps it when the form is saved empty', function (): void {
    $owner = W::user('Owner');
    $folder = W::restricted('Keeps', FolderAccessMode::Password, $owner, 'open sesame');
    $hash = (string) DB::table('lib_folders')->where('id', $folder->id)->value('password_hash');
    Gate::define('media.manage', static fn (): bool => true);

    $page = Livewire::actingAs($owner)->test(MediaLibraryPage::class)
        ->call('openFolder', $folder->uuid)
        ->mountAction('accessFolder');

    expectNoTrace(everythingSent($page), ['open sesame', $hash]);

    $page->callMountedAction()->assertHasNoActionErrors();

    expect((string) DB::table('lib_folders')->where('id', $folder->id)->value('password_hash'))->toBe($hash);
});

it('asks for confirmation before restricting a folder whose files are used', function (): void {
    $owner = W::user('Owner');
    $folder = W::folder('Public Now', null, $owner);
    $asset = W::asset('used-publicly', $folder);
    app(UsageRecorder::class)->sync(W::user('Page'), 'hero', [$asset->id]);
    Gate::define('media.manage', static fn (): bool => true);

    Livewire::actingAs($owner)->test(MediaLibraryPage::class)->call('openFolder', $folder->uuid)
        ->callAction('accessFolder', ['mode' => 'members', 'members' => []])
        ->assertHasActionErrors(['confirm_usage']);
    expect($folder->refresh()->access)->toBe('inherit');

    Livewire::actingAs($owner)->test(MediaLibraryPage::class)->call('openFolder', $folder->uuid)
        ->callAction('accessFolder', ['mode' => 'members', 'members' => [], 'confirm_usage' => true]);
    expect($folder->refresh()->access)->toBe('members');
});

// ---------------------------------------------------------------- the asset page

it('answers 404, not 403, to the asset page of a file the user cannot browse', function (): void {
    $world = secretLibrary();

    foreach ([$world['asset'], $world['nestedAsset']] as $asset) {
        Livewire::actingAs(W::user('Outsider'))->test(MediaAssetPage::class, ['asset' => $asset->uuid])->assertStatus(404);
    }

    Livewire::actingAs(W::user('Outsider'))
        ->test(MediaAssetPage::class, ['asset' => '00000000-0000-4000-8000-000000000000'])
        ->assertStatus(404);
});

it('sends a user to the unlock form for a file in a password folder, and shows it after the unlock', function (): void {
    $world = secretLibrary();
    $reader = W::user('Reader');
    $nested = W::folder('Inside the vault', $world['locked'], $world['owner']);
    $deep = W::asset('deep-minutes', $nested);

    foreach ([$world['lockedAsset'], $deep] as $asset) {
        Livewire::actingAs($reader)->test(MediaAssetPage::class, ['asset' => $asset->uuid])
            ->assertRedirect(MediaLibraryPage::getUrl(['folder' => $world['locked']->uuid]));
    }

    app(FolderAccess::class)->unlock($world['locked'], 'open sesame', $reader);

    Livewire::actingAs($reader)->test(MediaAssetPage::class, ['asset' => $world['lockedAsset']->uuid])
        ->assertOk()
        ->assertSee('gazelle-minutes.png');
});

it('shows the asset page to members and refuses it when the membership ends under an open page', function (): void {
    $world = secretLibrary();
    $member = W::user('Member');
    W::member($world['folder'], $member);

    $page = Livewire::actingAs($member)->test(MediaAssetPage::class, ['asset' => $world['asset']->uuid])
        ->assertOk()
        ->assertSee('narwhal-payslips.png');

    app(MediaFolderService::class)->removeMember($world['folder'], $member->id, '1');

    $page->call('$refresh')->assertDontSee('narwhal-payslips.png');
    expect($page->instance()->record())->toBeNull();
});

it('locks the route parameters of the asset page against a rewritten payload', function (): void {
    $world = secretLibrary();
    $mine = W::asset('mine');

    $page = Livewire::actingAs(W::user('Outsider'))->test(MediaAssetPage::class, ['asset' => $mine->uuid]);

    expect(fn () => $page->set('assetId', $world['asset']->id))->toThrow(Exception::class, 'Cannot update locked property')
        ->and(fn () => $page->set('asset', $world['asset']->uuid))->toThrow(Exception::class, 'Cannot update locked property');
});

it('keeps a member without manage from changing a file on its page, and lets a manager', function (): void {
    $world = secretLibrary();
    $viewer = W::user('Viewer');
    $manager = W::user('Manager');
    W::member($world['folder'], $viewer, 'view');
    W::member($world['folder'], $manager, 'manage');

    $page = Livewire::actingAs($viewer)->test(MediaAssetPage::class, ['asset' => $world['asset']->uuid])
        ->assertActionHidden('editDetails')
        ->assertActionHidden('replaceFile')
        ->assertActionHidden('editTags');
    $page->call('mountAction', 'editDetails')->call('callMountedAction', ['alt_text' => 'Hijacked']);
    $page->call('mountAction', 'moveAsset')->call('callMountedAction', ['destination' => null]);
    $page->call('storeFocalPoint', 0.1, 0.1);

    expect($world['asset']->refresh()->alt_text)->toBe('Alt for narwhal-payslips')
        ->and($world['asset']->folder_id)->toBe($world['folder']->id)
        ->and($world['asset']->focal_x)->toBe(0.5);

    Livewire::actingAs($manager)->test(MediaAssetPage::class, ['asset' => $world['asset']->uuid])
        ->callAction('editDetails', ['alt_text' => 'Updated by a manager', 'license_expires_at' => null])
        ->assertHasNoActionErrors();

    expect($world['asset']->refresh()->alt_text)->toBe('Updated by a manager');
});

it('does not let the asset page move a file into a folder the user cannot manage', function (): void {
    $world = secretLibrary();
    Gate::define('media.manage', static fn (): bool => true);
    $editor = W::user('Editor');
    $mine = W::asset('mine');

    Livewire::actingAs($editor)->test(MediaAssetPage::class, ['asset' => $mine->uuid])
        ->callAction('moveAsset', ['destination' => $world['folder']->uuid]);

    expect($mine->refresh()->folder_id)->toBeNull();
});

it('confirms before moving a used file into a restricted folder from its page', function (): void {
    $world = secretLibrary();
    $mine = W::asset('mine-in-use');
    app(UsageRecorder::class)->sync(W::user('Page'), 'hero', [$mine->id]);
    Gate::define('media.manage', static fn (): bool => true);

    Livewire::actingAs($world['owner'])->test(MediaAssetPage::class, ['asset' => $mine->uuid])
        ->callAction('moveAsset', ['destination' => $world['folder']->uuid])
        ->assertHasActionErrors(['confirm_usage']);

    expect($mine->refresh()->folder_id)->toBeNull();

    Livewire::actingAs($world['owner'])->test(MediaAssetPage::class, ['asset' => $mine->uuid])->callAction('moveAsset', ['destination' => $world['folder']->uuid, 'confirm_usage' => true]);

    expect($mine->refresh()->folder_id)->toBe($world['folder']->id);
});

it('leaves existing behaviour unchanged at the root and in shared folders', function (): void {
    Gate::define('media.manage', static fn (): bool => true);
    $user = W::user('Editor');
    $shared = W::folder('Everyone');
    $in = W::asset('in-shared', $shared);
    $root = W::asset('at-root');

    $page = Livewire::actingAs($user)->test(MediaLibraryPage::class)
        ->assertSee('at-root.png')
        ->assertActionVisible('upload')
        ->assertActionVisible('newFolder')
        ->assertDontSee('Locked')
        ->assertDontSee('Members only')
        ->call('openFolder', $shared->uuid)
        ->assertSee('in-shared.png');

    expect($page->instance()->canDelete($in))->toBeTrue()
        ->and($page->instance()->canDelete($root))->toBeTrue();
});

it('makes the members exactly the list in the form: adds, re-roles and removes', function (): void {
    $owner = W::user('Owner');
    $keep = W::user('Keep');
    $drop = W::user('Drop');
    $add = W::user('Add');
    $folder = W::restricted('Team', FolderAccessMode::Members, $owner);
    W::member($folder, $keep, 'view');
    W::member($folder, $drop, 'view');
    Gate::define('media.manage', static fn (): bool => true);

    Livewire::actingAs($owner)->test(MediaLibraryPage::class)
        ->call('openFolder', $folder->uuid)
        ->callAction('accessFolder', ['mode' => 'members', 'members' => [
            ['user_id' => $keep->id, 'role' => 'manage'],
            ['user_id' => $add->id, 'role' => 'view'],
        ]])
        ->assertHasNoActionErrors();

    expect(DB::table('lib_folder_members')->where('media_folder_id', $folder->id)->pluck('role', 'user_id')->all())
        ->toBe([$keep->id => 'manage', $add->id => 'view']);

    // The form refuses an unknown user or role, and the sync ignores them too if it is ever handed them.
    Livewire::actingAs($owner)->test(MediaLibraryPage::class)
        ->call('openFolder', $folder->uuid)
        ->callAction('accessFolder', ['mode' => 'members', 'members' => [['user_id' => 999999, 'role' => 'view']]])
        ->assertHasActionErrors();

    $page = Livewire::actingAs($owner)->test(MediaLibraryPage::class)->instance();
    (new ReflectionMethod($page, 'syncMembers'))->invoke($page, $folder, [
        ['user_id' => $keep->id, 'role' => 'view'],
        ['user_id' => 999999, 'role' => 'view'],
        ['user_id' => 'not-a-user', 'role' => 'view'],
        ['user_id' => $drop->id, 'role' => 'owner'],
        'junk',
    ], (string) $owner->id);

    expect(DB::table('lib_folder_members')->where('media_folder_id', $folder->id)->pluck('role', 'user_id')->all())
        ->toBe([$keep->id => 'view']);
});

it('finds members to add by the configured attribute and falls back to the email', function (): void {
    $page = Livewire::actingAs(W::user('Owner'))->test(MediaLibraryPage::class)->instance();
    W::user('Zelda Findable');
    $call = static function (string $method, mixed ...$args) use ($page): mixed {
        return (new ReflectionMethod($page, $method))->invoke($page, ...$args);
    };

    expect(array_values($call('memberOptions', 'findable')))->toBe(['Zelda Findable'])
        ->and($call('memberOptions', 'nothing-like-this'))->toBe([])
        ->and($call('memberLabel', W::user('Zelda Findable')->id))->toBe('Zelda Findable')
        ->and($call('memberLabel', 999999))->toBeNull()
        ->and($call('memberLabel', 'abc'))->toBeNull();

    config()->set('media-library.folders.member_label', 'no_such_column');

    expect(array_values($call('memberOptions', 'zelda')))->toBe(['zelda findable@example.test']);

    config()->set('auth.providers.users.model', null);

    expect($call('memberOptions', 'zelda'))->toBe([])->and($call('memberLabel', 1))->toBeNull();
});
