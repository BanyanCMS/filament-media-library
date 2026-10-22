<?php

declare(strict_types=1);

use Banyan\FilamentMediaLibrary\Access\FolderAccess;
use Banyan\FilamentMediaLibrary\Enums\FolderAccessMode;
use Banyan\FilamentMediaLibrary\Folders\MediaFolderService;
use Banyan\FilamentMediaLibrary\Forms\MediaPicker;
use Banyan\FilamentMediaLibrary\Models\MediaAsset;
use Banyan\FilamentMediaLibrary\Models\MediaFolder;
use Banyan\FilamentMediaLibrary\Tests\Support\AccessWorld as W;
use Banyan\FilamentMediaLibrary\Tests\Support\Images;
use Banyan\FilamentMediaLibrary\Tests\Support\PickerForm;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

covers(MediaPicker::class);

beforeEach(function (): void {
    view()->addNamespace('media-library-tests', __DIR__.'/Support/views');
});

function accessPicker(Testable $test, string $name = 'hero_id'): MediaPicker
{
    $field = $test->instance()->getSchema('form')?->getComponent($name);
    assert($field instanceof MediaPicker);

    return $field;
}

function inlineUpload(string $field = 'hero_id'): TestAction
{
    return TestAction::make('uploadMedia')->schemaComponent($field);
}

/** @return array{owner: mixed, member: mixed, folder: MediaFolder, secret: MediaAsset, open: MediaAsset, vault: MediaFolder, vaultAsset: MediaAsset} */
function pickerWorld(): array
{
    $owner = W::user('Owner');
    $member = W::user('Member');
    $folder = W::restricted('Hidden Zebra Folder', FolderAccessMode::Members, $owner);
    W::member($folder, $member);
    $vault = W::restricted('Hidden Ibex Vault', FolderAccessMode::Password, $owner, 'open sesame');

    return [
        'owner' => $owner,
        'member' => $member,
        'folder' => $folder,
        'secret' => W::asset('secret-narwhal', $folder),
        'open' => W::asset('open-badger'),
        'vault' => $vault,
        'vaultAsset' => W::asset('vault-gazelle', $vault),
    ];
}

it('leaves a members folder\'s files out of the options, the search, the labels and the payload for a non-member', function (): void {
    $world = pickerWorld();

    $test = Livewire::actingAs(W::user('Outsider'))->test(PickerForm::class)
        ->set('data.hero_id', $world['secret']->id)
        ->set('data.gallery', [$world['secret']->id, $world['open']->id]);
    $hero = accessPicker($test);
    $gallery = accessPicker($test, 'gallery');

    expect(array_keys($hero->getOptions()))->toBe([$world['open']->id])
        ->and($hero->getSearchResults('narwhal'))->toBe([])
        ->and($hero->getSearchResults('secret'))->toBe([])
        // a held value that is hidden reads as missing, never by name
        ->and($hero->getOptionLabel())->toBe('Deleted image (#'.$world['secret']->id.')')
        ->and(array_keys($gallery->getOptionLabels()))->toEqualCanonicalizing([$world['open']->id, $world['secret']->id])
        ->and(implode('|', $gallery->getOptionLabels()))->not->toContain('narwhal');

    foreach (['secret-narwhal', 'Hidden Zebra Folder', $world['folder']->uuid, $world['secret']->uuid, 'vault-gazelle', 'Hidden Ibex Vault'] as $needle) {
        expect($test->html().json_encode($test->snapshot))->not->toContain($needle);
    }
});

it('hides restricted folders from a member too, until the field opts in with allowRestricted', function (): void {
    $world = pickerWorld();
    $member = $world['member'];

    $default = accessPicker(Livewire::actingAs($member)->test(PickerForm::class));

    expect(array_keys($default->getOptions()))->toBe([$world['open']->id])
        ->and($default->getSearchResults('narwhal'))->toBe([])
        ->and($default->restrictedAllowed())->toBeFalse();

    $opted = accessPicker(Livewire::actingAs($member)->test(PickerForm::class, ['allowRestricted' => true]));

    expect(array_keys($opted->getOptions()))->toEqualCanonicalizing([$world['open']->id, $world['secret']->id])
        ->and($opted->getSearchResults('narwhal'))->toHaveCount(1)
        ->and($opted->restrictedAllowed())->toBeTrue();
});

it('shows allowRestricted fields only what the user can open: nothing for an outsider, a password folder only once unlocked', function (): void {
    $world = pickerWorld();
    $reader = W::user('Reader');

    $outsider = accessPicker(Livewire::actingAs($reader)->test(PickerForm::class, ['allowRestricted' => true]));

    expect(array_keys($outsider->getOptions()))->toBe([$world['open']->id]);

    app(FolderAccess::class)->unlock($world['vault'], 'open sesame', $reader);

    $after = accessPicker(Livewire::actingAs($reader)->test(PickerForm::class, ['allowRestricted' => true]));
    $default = accessPicker(Livewire::actingAs($reader)->test(PickerForm::class));

    expect(array_keys($after->getOptions()))->toEqualCanonicalizing([$world['open']->id, $world['vaultAsset']->id])
        // unlocked, but a public field still may not use it
        ->and(array_keys($default->getOptions()))->toBe([$world['open']->id]);
});

it('offers manage-all every file when the field allows restricted ones, and the owner their own', function (): void {
    $world = pickerWorld();
    $admin = W::user('Admin');
    Gate::define('media.manage-all', static fn ($user): bool => $user->name === 'Admin');

    $all = accessPicker(Livewire::actingAs($admin)->test(PickerForm::class, ['allowRestricted' => true]));
    $mine = accessPicker(Livewire::actingAs($world['owner'])->test(PickerForm::class, ['allowRestricted' => true]));

    expect(array_keys($all->getOptions()))->toEqualCanonicalizing([$world['open']->id, $world['secret']->id, $world['vaultAsset']->id])
        ->and(array_keys($mine->getOptions()))->toEqualCanonicalizing([$world['open']->id, $world['secret']->id, $world['vaultAsset']->id]);
});

it('marks a restricted file a field holds but may not offer', function (): void {
    $world = pickerWorld();

    $test = Livewire::actingAs($world['member'])->test(PickerForm::class)->set('data.hero_id', $world['secret']->id);

    expect(accessPicker($test)->getOptionLabel())->toContain('secret-narwhal')->toContain('restricted');
});

it('refuses to save a value the picker would not have offered', function (): void {
    $world = pickerWorld();
    $state = static function (Testable $test): array {
        return $test->instance()->getSchema('form')?->getState() ?? [];
    };

    // an outsider naming the id of a hidden file
    $outsider = Livewire::actingAs(W::user('Outsider'))->test(PickerForm::class)->set('data.hero_id', $world['secret']->id);
    expect(fn () => $state($outsider))->toThrow(ValidationException::class);

    // a member on a public field
    $member = Livewire::actingAs($world['member'])->test(PickerForm::class)->set('data.gallery', [$world['open']->id, $world['secret']->id]);
    expect(fn () => $state($member))->toThrow(ValidationException::class);

    // ... but not on a field that allows it, and a plain file is always fine
    $allowed = Livewire::actingAs($world['member'])->test(PickerForm::class, ['allowRestricted' => true])->set('data.hero_id', $world['secret']->id);
    expect($state($allowed)['hero_id'])->toBe($world['secret']->id);

    $plain = Livewire::actingAs(W::user('Outsider'))->test(PickerForm::class)->set('data.hero_id', $world['open']->id);
    expect($state($plain)['hero_id'])->toBe($world['open']->id);

    // an empty field is not a hidden value
    $empty = Livewire::actingAs(W::user('Outsider'))->test(PickerForm::class);
    expect($state($empty)['hero_id'] ?? null)->toBeNull();
});

it('scopes to a restricted folder only for a field that allows it, and only for those who can open it', function (): void {
    $world = pickerWorld();

    $public = accessPicker(Livewire::actingAs($world['member'])->test(PickerForm::class, ['folder' => $world['folder']->uuid]))->getOptions();
    $opted = accessPicker(Livewire::actingAs($world['member'])->test(PickerForm::class, ['folder' => $world['folder']->uuid, 'allowRestricted' => true]))->getOptions();
    $outsider = accessPicker(Livewire::actingAs(W::user('Outsider'))->test(PickerForm::class, ['folder' => $world['folder']->uuid, 'allowRestricted' => true]))->getOptions();

    expect($public)->toBe([])
        ->and(array_keys($opted))->toBe([$world['secret']->id])
        ->and($outsider)->toBe([]);
});

it('offers inline upload into a restricted folder only with allowRestricted and the right to manage it', function (): void {
    $world = pickerWorld();
    $manager = W::user('Manager');
    $viewer = W::user('Viewer');
    W::member($world['folder'], $manager, 'manage');
    W::member($world['folder'], $viewer, 'view');
    $payload = ['file' => UploadedFile::fake()->createWithContent('inline.png', Images::splitPng()), 'alt_text' => 'Inline'];
    $scoped = ['folder' => $world['folder']->uuid, 'allowRestricted' => true];

    // not allowed by the field
    Livewire::actingAs($manager)->test(PickerForm::class, ['folder' => $world['folder']->uuid])->assertActionHidden(inlineUpload());
    // allowed by the field, but a view member cannot manage
    Livewire::actingAs($viewer)->test(PickerForm::class, $scoped)->assertActionHidden(inlineUpload());
    // an outsider with media.manage elsewhere still cannot reach into it
    Gate::define('media.manage', static fn ($user): bool => $user->name === 'Editor');
    Livewire::actingAs(W::user('Editor'))->test(PickerForm::class, $scoped)->assertActionHidden(inlineUpload());

    Livewire::actingAs($manager)->test(PickerForm::class, $scoped)
        ->assertActionVisible(inlineUpload())
        ->callAction(inlineUpload(), $payload)
        ->assertHasNoActionErrors();

    expect(MediaAsset::query()->where('alt_text', 'Inline')->sole()->folder_id)->toBe($world['folder']->id);
});

it('refuses a forged inline upload into a folder the user lost the right to manage', function (): void {
    $world = pickerWorld();
    $manager = W::user('Manager');
    W::member($world['folder'], $manager, 'manage');

    $test = Livewire::actingAs($manager)->test(PickerForm::class, ['folder' => $world['folder']->uuid, 'allowRestricted' => true])
        ->mountAction(inlineUpload());

    app(MediaFolderService::class)->removeMember($world['folder'], $manager->id, '1');

    $test->setActionData(['file' => UploadedFile::fake()->createWithContent('forged.png', Images::splitPng()), 'alt_text' => 'Forged'])->callMountedAction();

    expect(MediaAsset::query()->where('alt_text', 'Forged')->exists())->toBeFalse();
});

it('does not upload into a scoped folder that cannot be found, instead of falling back to the root', function (): void {
    Gate::define('media.manage', static fn (): bool => true);

    Livewire::actingAs(W::user('Editor'))
        ->test(PickerForm::class, ['folder' => '00000000-0000-4000-8000-000000000000'])
        ->assertActionHidden(inlineUpload());
});

it('still offers the usual root upload to someone who may manage the library', function (): void {
    Gate::define('media.manage', static fn (): bool => true);

    Livewire::actingAs(W::user('Editor'))->test(PickerForm::class)
        ->assertActionVisible(inlineUpload())
        ->callAction(inlineUpload(), ['file' => UploadedFile::fake()->createWithContent('root.png', Images::splitPng()), 'alt_text' => 'At the root'])
        ->assertHasNoActionErrors();

    expect(MediaAsset::query()->where('alt_text', 'At the root')->sole()->folder_id)->toBeNull();
});
