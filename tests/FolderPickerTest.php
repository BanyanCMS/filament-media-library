<?php

declare(strict_types=1);

use Banyan\FilamentMediaLibrary\Folders\MediaFolderService;
use Banyan\FilamentMediaLibrary\Forms\MediaPicker;
use Banyan\FilamentMediaLibrary\Models\MediaAsset;
use Banyan\FilamentMediaLibrary\Models\MediaFolder;
use Banyan\FilamentMediaLibrary\Tests\Support\Images;
use Banyan\FilamentMediaLibrary\Tests\Support\PickerForm;
use Banyan\FilamentMediaLibrary\Tests\Support\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

covers(MediaPicker::class);

beforeEach(function (): void {
    view()->addNamespace('media-library-tests', __DIR__.'/Support/views');
});

function folderPicker(Testable $test): MediaPicker
{
    $field = $test->instance()->getSchema('form')?->getComponent('hero_id');
    assert($field instanceof MediaPicker);

    return $field;
}

/** @return array{reports: MediaFolder, year: MediaFolder, press: MediaFolder, assets: array<string, MediaAsset>} */
function pickerLibrary(): array
{
    $service = app(MediaFolderService::class);
    $reports = $service->create(null, 'Reports', '1');
    $year = $service->create($reports, '2025', '1');
    $press = $service->create(null, 'Press', '1');
    $assets = ['root' => Images::upload('Root image'), 'reports' => Images::upload('Reports image'), 'year' => Images::upload('Year image'), 'press' => Images::upload('Press image')];
    $service->moveAssets([$assets['reports']], $reports, '1');
    $service->moveAssets([$assets['year']], $year, '1');
    $service->moveAssets([$assets['press']], $press, '1');

    return compact('reports', 'year', 'press', 'assets');
}

it('offers the whole library by default, with the folder path on filed assets', function (): void {
    $lib = pickerLibrary();

    $options = folderPicker(Livewire::actingAs(User::make())->test(PickerForm::class))->getOptions();

    expect($options)->toHaveCount(4)
        ->and($options[$lib['assets']['root']->id])->toBe('split.png — Root image')
        ->and($options[$lib['assets']['year']->id])->toBe('split.png — Year image — in Reports / 2025')
        ->and($options[$lib['assets']['press']->id])->toContain('in Press');
});

it('scopes the options to a folder and everything below it', function (): void {
    $lib = pickerLibrary();

    $field = folderPicker(Livewire::actingAs(User::make())->test(PickerForm::class, ['folder' => $lib['reports']->uuid]));

    expect(array_keys($field->getOptions()))->toEqualCanonicalizing([$lib['assets']['reports']->id, $lib['assets']['year']->id])
        ->and(array_keys($field->getSearchResults('image')))->toEqualCanonicalizing([$lib['assets']['reports']->id, $lib['assets']['year']->id])
        ->and($field->getFolder()?->is($lib['reports']))->toBeTrue();

    $deep = folderPicker(Livewire::actingAs(User::make())->test(PickerForm::class, ['folder' => $lib['year']->uuid]));
    expect(array_keys($deep->getOptions()))->toBe([$lib['assets']['year']->id]);
});

it('accepts a folder model as well as a uuid', function (): void {
    $lib = pickerLibrary();

    $field = MediaPicker::make('x')->folder($lib['press']);

    expect($field->getFolder()?->is($lib['press']))->toBeTrue()
        ->and(MediaPicker::make('y')->folder(null)->getFolder())->toBeNull();
});

it('offers nothing, not everything, for a folder that cannot be found', function (): void {
    pickerLibrary();

    $field = folderPicker(Livewire::actingAs(User::make())->test(PickerForm::class, ['folder' => '00000000-0000-4000-8000-000000000000']));

    expect($field->getOptions())->toBe([])->and($field->getFolder())->toBeNull();
});

it('ignores the folder scope and the path suffix when folders are disabled', function (): void {
    $lib = pickerLibrary();
    config()->set('media-library.folders.enabled', false);

    $field = folderPicker(Livewire::actingAs(User::make())->test(PickerForm::class, ['folder' => $lib['press']->uuid]));

    expect($field->getOptions())->toHaveCount(4)
        ->and($field->getOptions()[$lib['assets']['year']->id])->toBe('split.png — Year image')
        ->and($field->getFolder())->toBeNull();
});

it('labels a selected asset, single or multiple, with its folder path', function (): void {
    $lib = pickerLibrary();
    $year = $lib['assets']['year'];

    $test = Livewire::actingAs(User::make())->test(PickerForm::class)
        ->set('data.hero_id', $year->id)
        ->set('data.gallery', [$year->id, $lib['assets']['root']->id]);

    $gallery = $test->instance()->getSchema('form')?->getComponent('gallery');
    assert($gallery instanceof MediaPicker);

    expect(folderPicker($test)->getOptionLabel())->toBe('split.png — Year image — in Reports / 2025')
        ->and($gallery->getOptionLabels())->toEqual([
            $year->id => 'split.png — Year image — in Reports / 2025',
            $lib['assets']['root']->id => 'split.png — Root image',
        ]);
});

it('files an inline upload into the scoped folder', function (): void {
    Gate::define('media.manage', static fn (): bool => true);
    $lib = pickerLibrary();

    Livewire::actingAs(User::make())
        ->test(PickerForm::class, ['folder' => $lib['press']->uuid])
        ->callAction(TestAction::make('uploadMedia')->schemaComponent('hero_id'), [
            'file' => UploadedFile::fake()->createWithContent('campus.png', Images::splitPng()),
            'alt_text' => 'Inline',
        ])
        ->assertHasNoActionErrors();

    expect(MediaAsset::query()->where('alt_text', 'Inline')->sole()->folder_id)->toBe($lib['press']->id);
});
