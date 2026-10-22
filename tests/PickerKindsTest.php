<?php

declare(strict_types=1);

use Banyan\FilamentMediaLibrary\Admin\Support\MediaForms;
use Banyan\FilamentMediaLibrary\Enums\MediaKind;
use Banyan\FilamentMediaLibrary\Forms\MediaPicker;
use Banyan\FilamentMediaLibrary\Governance\MediaGovernanceService;
use Banyan\FilamentMediaLibrary\Models\MediaAsset;
use Banyan\FilamentMediaLibrary\Tests\Support\Files;
use Banyan\FilamentMediaLibrary\Tests\Support\Images;
use Banyan\FilamentMediaLibrary\Tests\Support\PickerForm;
use Banyan\FilamentMediaLibrary\Tests\Support\User;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\FileUpload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

covers(MediaPicker::class, MediaForms::class);

beforeEach(function (): void {
    view()->addNamespace('media-library-tests', __DIR__.'/Support/views');
});

/** @return array<string, MediaAsset> one asset of every kind */
function everyKind(): array
{
    $service = app(MediaGovernanceService::class);

    return [
        'image' => Images::upload('A picture'),
        'document' => $service->upload(Files::pdf(), 'annual.pdf', 'application/pdf', '', '1', null, 'Annual report'),
        'video' => $service->upload(Files::mp4(), 'clip.mp4', 'video/mp4', '', '1', null, 'Welcome film'),
        'audio' => $service->upload(Files::mp3(), 'talk.mp3', 'audio/mpeg', '', '1', null, 'The talk'),
        'archive' => $service->upload(Files::zip(), 'bundle.zip', 'application/zip', '', '1', null, 'Bundle'),
    ];
}

/** @param list<string> $kinds */
function kindPicker(array $kinds, bool $thumbnails = false): Testable
{
    return Livewire::actingAs(User::query()->first() ?? User::make())->test(PickerForm::class, ['kinds' => $kinds, 'thumbnails' => $thumbnails]);
}

function pickerKindsField(Testable $test): MediaPicker
{
    $field = $test->instance()->getSchema('form')?->getComponent('hero_id');
    assert($field instanceof MediaPicker);

    return $field;
}

it('offers only images by default, so existing fields are unchanged', function (): void {
    $assets = everyKind();

    $field = pickerKindsField(kindPicker(['image']));

    expect(array_keys($field->getOptions()))->toBe([$assets['image']->id])
        ->and($field->getKinds())->toBe([MediaKind::Image])
        ->and($field->getPlaceholder())->toBe('Choose an image');

    expect(MediaPicker::make('x')->getKinds())->toBe([MediaKind::Image]);
});

it('filters the options and the search results by the kinds it was given', function (string $method, string $kind): void {
    $assets = everyKind();
    $picker = MediaPicker::make('x');
    $picker->{$method}();

    expect($picker->getKinds())->toBe([MediaKind::from($kind)]);

    $field = pickerKindsField(kindPicker([$kind]));

    expect(array_keys($field->getOptions()))->toBe([$assets[$kind]->id])
        ->and($field->getPlaceholder())->toBe($kind === 'image' ? 'Choose an image' : 'Choose a file');
})->with([
    ['images', 'image'],
    ['documents', 'document'],
    ['videos', 'video'],
    ['audio', 'audio'],
    ['archives', 'archive'],
]);

it('combines kinds, offers any kind, and never falls back to everything for an empty list', function (): void {
    $assets = everyKind();

    $two = pickerKindsField(kindPicker(['document', 'video']));
    $any = MediaPicker::make('x')->anyKind();

    expect(array_keys($two->getOptions()))->toEqualCanonicalizing([$assets['document']->id, $assets['video']->id])
        ->and($any->getKinds())->toBe(MediaKind::cases())
        ->and(MediaPicker::make('x')->kinds([])->getKinds())->toBe([MediaKind::Image])
        ->and(MediaPicker::make('x')->kinds([MediaKind::Audio, MediaKind::Audio])->getKinds())->toBe([MediaKind::Audio]);

    $everything = pickerKindsField(kindPicker(array_map(static fn (MediaKind $kind): string => $kind->value, MediaKind::cases())));
    expect($everything->getOptions())->toHaveCount(5);
});

it('labels non-images with their title and kind, and images as before', function (): void {
    $assets = everyKind();

    $field = pickerKindsField(kindPicker(['image', 'document']));
    $options = $field->getOptions();

    expect($options[$assets['document']->id])->toBe('Annual report — Document')
        ->and($options[$assets['image']->id])->toBe('split.png — A picture')
        ->and(array_keys($field->getSearchResults('annual')))->toBe([$assets['document']->id])
        ->and($field->getOptionLabel() ?? null)->toBeNull();
});

it('searches documents by title, and shows their kind icon beside the label when thumbnails are on', function (): void {
    $assets = everyKind();

    $field = pickerKindsField(kindPicker(['document', 'image'], thumbnails: true));
    $options = $field->getOptions();

    expect($options[$assets['document']->id])->toContain('<svg')->toContain('bn-media-picker__icon')->toContain('Annual report')->not->toContain('<img')->not->toContain('style=')
        ->and($options[$assets['image']->id])->toContain('<img')->toContain('w=48');
});

it('rejects an image in the upload form of a documents field', function (): void {
    Gate::define('media.manage', static fn (): bool => true);

    kindPicker(['document'])
        ->callAction(TestAction::make('uploadMedia')->schemaComponent('hero_id'), [
            'file' => UploadedFile::fake()->createWithContent('campus.png', Images::splitPng()),
            'title' => 'Campus',
        ])
        ->assertHasActionErrors(['file']);

    expect(MediaAsset::query()->count())->toBe(0);
});

it('refuses, with a notification, a kind the field does not offer even if the form let it through', function (): void {
    Gate::define('media.manage', static fn (): bool => true);
    // A misconfigured allow-list that lets the browser-side check pass: the handler still checks the bytes.
    config(['media-library.uploads.kinds.document.mime_types' => ['application/pdf', 'image/png']]);

    kindPicker(['document'])
        ->callAction(TestAction::make('uploadMedia')->schemaComponent('hero_id'), [
            'file' => UploadedFile::fake()->createWithContent('campus.png', Images::splitPng()),
            'title' => 'Campus',
        ])
        ->assertSet('data.hero_id', null)
        ->assertNotified(__('media-library::media.upload_failed'));

    expect(MediaAsset::query()->count())->toBe(0);
});

it('uploads a pdf with a title in a documents picker and selects it', function (): void {
    Gate::define('media.manage', static fn (): bool => true);

    $test = kindPicker(['document'])
        ->callAction(TestAction::make('uploadMedia')->schemaComponent('hero_id'), [
            'file' => UploadedFile::fake()->createWithContent('minutes.pdf', Files::pdfWithPages(3)),
            'title' => 'Minutes of March',
            'tags' => 'Minutes',
        ])
        ->assertHasNoActionErrors();

    $asset = MediaAsset::query()->sole();

    $test->assertSet('data.hero_id', $asset->id);

    expect($asset->kind)->toBe(MediaKind::Document)
        ->and($asset->title)->toBe('Minutes of March')
        ->and($asset->alt_text)->toBeNull()
        ->and($asset->page_count)->toBe(3)
        ->and($asset->tags->pluck('name')->all())->toBe(['minutes']);
});

it('requires a title, and no alt text, in the upload form of a field without images', function (): void {
    Gate::define('media.manage', static fn (): bool => true);

    kindPicker(['document'])
        ->callAction(TestAction::make('uploadMedia')->schemaComponent('hero_id'), [
            'file' => UploadedFile::fake()->createWithContent('minutes.pdf', Files::pdf()),
        ])
        ->assertHasActionErrors(['title' => 'required']);

    $names = static fn (array $components): array => array_map(static fn ($c): string => $c->getName(), $components);

    expect($names(MediaForms::upload([MediaKind::Document, MediaKind::Video])))->toBe(['file', 'title', 'license_expires_at', 'tags'])
        ->and($names(MediaForms::upload([MediaKind::Image, MediaKind::Document])))->toBe(['file', 'title', 'alt_text', 'license_expires_at', 'tags']);

    $file = MediaForms::upload([MediaKind::Document])[0];
    assert($file instanceof FileUpload);
    expect($file->getAcceptedFileTypes())->toContain('application/pdf', 'text/vtt')->not->toContain('image/png');
});

it('still uploads an image with alt text in the default picker', function (): void {
    Gate::define('media.manage', static fn (): bool => true);

    $test = kindPicker(['image'])
        ->callAction(TestAction::make('uploadMedia')->schemaComponent('hero_id'), [
            'file' => UploadedFile::fake()->createWithContent('campus.png', Images::splitPng()),
            'alt_text' => 'The campus',
        ])
        ->assertHasNoActionErrors();

    $test->assertSet('data.hero_id', MediaAsset::query()->sole()->id);
});
