<?php

declare(strict_types=1);

use Banyan\FilamentMediaLibrary\Forms\MediaPicker;
use Banyan\FilamentMediaLibrary\Models\MediaAsset;
use Banyan\FilamentMediaLibrary\Tests\Support\Images;
use Banyan\FilamentMediaLibrary\Tests\Support\PickerForm;
use Banyan\FilamentMediaLibrary\Tests\Support\User;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Select;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

covers(MediaPicker::class);

beforeEach(function (): void {
    view()->addNamespace('media-library-tests', __DIR__.'/Support/views');
});

/** The mounted field, so its options and labels are read from the component and not from the HTML. */
function picker(Testable $test, string $name = 'hero_id'): MediaPicker
{
    $field = $test->instance()->getSchema('form')?->getComponent($name);
    assert($field instanceof MediaPicker);

    return $field;
}

function pickerAction(string $field = 'hero_id'): TestAction
{
    return TestAction::make('uploadMedia')->schemaComponent($field);
}

function uploadPayload(string $alt = 'The campus at dusk'): array
{
    return [
        'file' => UploadedFile::fake()->createWithContent('campus.png', Images::splitPng()),
        'alt_text' => $alt,
    ];
}

it('is a searchable, non-native select', function (): void {
    $field = picker(Livewire::actingAs(User::make())->test(PickerForm::class));

    expect($field)->toBeInstanceOf(Select::class)
        ->and($field->isSearchable())->toBeTrue()
        ->and($field->isNative())->toBeFalse()
        ->and($field->isMultiple())->toBeFalse()
        ->and($field->getPlaceholder())->toBe('Choose an image')
        ->and($field->getSearchPrompt())->toBe('Search filename or alt text');
});

it('offers selectable assets newest first and leaves expired ones out', function (): void {
    $old = Images::upload('Old');
    $expired = Images::upload('Lapsed', '1', '-1 day');
    $new = Images::upload('New');

    $options = picker(Livewire::actingAs(User::make())->test(PickerForm::class))->getOptions();

    expect(array_keys($options))->toBe([$new->id, $old->id])
        ->and($options[$new->id])->toBe('split.png — New')
        ->and($options)->not->toHaveKey($expired->id);
});

it('caps the options at fifty', function (): void {
    foreach (range(1, 52) as $i) {
        Images::upload('Image '.$i);
    }

    expect(picker(Livewire::actingAs(User::make())->test(PickerForm::class))->getOptions())->toHaveCount(50);
});

it('searches filename and alt text', function (): void {
    $logo = Images::upload('Company logo');
    $beach = Images::upload('A sunny beach');
    $field = picker(Livewire::actingAs(User::make())->test(PickerForm::class));

    expect(array_keys($field->getSearchResults('beach')))->toBe([$beach->id])
        ->and(array_keys($field->getSearchResults('LOGO')))->toBe([$logo->id])
        ->and(array_keys($field->getSearchResults('split')))->toBe([$beach->id, $logo->id])
        ->and($field->getSearchResults('nothing like this'))->toBe([])
        // A literal wildcard is a character to find, not a pattern.
        ->and($field->getSearchResults('%'))->toBe([]);
});

it('labels an expired asset that is already picked as expired', function (): void {
    $expired = Images::upload('Lapsed', '1', '-1 day');
    $live = Images::upload('Fine');

    $test = Livewire::actingAs(User::make())
        ->test(PickerForm::class)
        ->set('data.hero_id', $expired->id)
        ->set('data.gallery', [$live->id, $expired->id]);

    expect(picker($test)->getOptionLabel())->toBe('split.png — Lapsed — '.__('media-library::media.expired'))
        ->and(picker($test, 'gallery')->getOptionLabels())->toEqual([
            $live->id => 'split.png — Fine',
            $expired->id => 'split.png — Lapsed — '.__('media-library::media.expired'),
        ]);
});

it('resolves no label for an asset that no longer exists', function (): void {
    $test = Livewire::actingAs(User::make())->test(PickerForm::class)->set('data.gallery', [9999]);

    expect(picker($test, 'gallery')->getOptionLabels())->toBe([]);
});

it('renders escaped thumbnails with the asset alt text when asked', function (): void {
    $asset = Images::upload('A "quoted" <b>name</b>');

    $label = picker(Livewire::actingAs(User::make())->test(PickerForm::class, ['thumbnails' => true]))
        ->getOptions()[$asset->id];

    // A string, never an HtmlString: Livewire would serialise the object and the select shows "[object Object]".
    expect($label)->toBeString()
        ->and($label)->toContain('alt="A &quot;quoted&quot; &lt;b&gt;name&lt;/b&gt;"')
        ->and($label)->toContain('w=48')
        ->and($label)->toContain('signature=')
        ->and($label)->toContain('class="bn-media-picker__thumb"')
        ->and($label)->not->toContain('style=')
        ->and($label)->not->toContain('<b>');
});

it('hides the upload action from a user who may not manage the library', function (): void {
    Livewire::actingAs(User::make())
        ->test(PickerForm::class)
        ->assertActionHidden(pickerAction());
});

it('hides the upload action from a guest even where the ability allows everyone', function (): void {
    Gate::define('media.manage', static fn (): bool => true);

    Livewire::test(PickerForm::class)->assertActionHidden(pickerAction());
});

it('shows the upload action to a user who may manage', function (): void {
    Gate::define('media.manage', static fn (): bool => true);

    Livewire::actingAs(User::make())
        ->test(PickerForm::class)
        ->assertActionVisible(pickerAction())
        ->assertActionVisible(pickerAction('gallery'));
});

it('hides the upload action when the field is made not uploadable', function (): void {
    Gate::define('media.manage', static fn (): bool => true);

    Livewire::actingAs(User::make())
        ->test(PickerForm::class, ['uploadable' => false])
        ->assertActionHidden(pickerAction());
});

it('uploads and selects the new asset', function (): void {
    Gate::define('media.manage', static fn (): bool => true);
    $user = User::make();

    $test = Livewire::actingAs($user)
        ->test(PickerForm::class)
        ->callAction(pickerAction(), [...uploadPayload(), 'tags' => 'Campus'])
        ->assertHasNoActionErrors();

    $asset = MediaAsset::query()->sole();

    $test->assertSet('data.hero_id', $asset->id);

    expect($asset->alt_text)->toBe('The campus at dusk')
        ->and($asset->tags->pluck('name')->all())->toBe(['campus'])
        ->and($asset->uploaded_by)->toBe($user->id);
});

it('appends the new asset to a multiple field', function (): void {
    Gate::define('media.manage', static fn (): bool => true);
    $first = Images::upload('First');

    $test = Livewire::actingAs(User::make())
        ->test(PickerForm::class)
        ->set('data.gallery', [$first->id])
        ->callAction(pickerAction('gallery'), uploadPayload())
        ->assertHasNoActionErrors();

    $second = MediaAsset::query()->where('alt_text', 'The campus at dusk')->sole();

    $test->assertSet('data.gallery', [$first->id, $second->id]);
});

it('requires alt text in the upload form', function (): void {
    Gate::define('media.manage', static fn (): bool => true);

    Livewire::actingAs(User::make())
        ->test(PickerForm::class)
        ->callAction(pickerAction(), [...uploadPayload(), 'alt_text' => ''])
        ->assertHasActionErrors(['alt_text' => 'required']);

    expect(MediaAsset::query()->count())->toBe(0);
});

it('does not upload when the ability is withdrawn after the form was shown', function (): void {
    $allowed = true;
    Gate::define('media.manage', static function () use (&$allowed): bool {
        return $allowed;
    });

    $test = Livewire::actingAs(User::make())
        ->test(PickerForm::class)
        ->mountAction(pickerAction());

    $allowed = false;

    $test->setActionData(uploadPayload())->callMountedAction();

    expect(MediaAsset::query()->count())->toBe(0);
});

it('stores nothing and notifies when the file cannot be read as an image', function (): void {
    Gate::define('media.manage', static fn (): bool => true);

    Livewire::actingAs(User::make())
        ->test(PickerForm::class)
        ->callAction(pickerAction(), [
            'file' => UploadedFile::fake()->createWithContent('broken.png', 'not an image'),
            'alt_text' => 'Broken',
        ])
        ->assertSet('data.hero_id', null)
        ->assertNotified(__('media-library::media.upload_failed'));

    expect(MediaAsset::query()->count())->toBe(0);
});
