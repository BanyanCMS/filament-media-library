<?php

declare(strict_types=1);

use Banyan\FilamentMediaLibrary\Enums\MediaKind;
use Banyan\FilamentMediaLibrary\Forms\MediaForms;
use Banyan\FilamentMediaLibrary\Forms\MediaPicker;
use Banyan\FilamentMediaLibrary\Governance\MediaGovernanceService;
use Banyan\FilamentMediaLibrary\Models\MediaAsset;
use Banyan\FilamentMediaLibrary\Pages\MediaAssetPage;
use Banyan\FilamentMediaLibrary\Pages\MediaLibraryPage;
use Banyan\FilamentMediaLibrary\Tables\MediaThumbnailColumn;
use Banyan\FilamentMediaLibrary\Tests\Support\Files;
use Banyan\FilamentMediaLibrary\Tests\Support\Images;
use Banyan\FilamentMediaLibrary\Tests\Support\PickerForm;
use Banyan\FilamentMediaLibrary\Tests\Support\User;
use Filament\Forms\Components\FileUpload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

covers(MediaForms::class, MediaLibraryPage::class, MediaAssetPage::class, MediaPicker::class, MediaThumbnailColumn::class);

beforeEach(function (): void {
    view()->addNamespace('media-library-tests', __DIR__.'/Support/views');
});

function pdfAsset(string $title = 'Annual report 2025'): MediaAsset
{
    return app(MediaGovernanceService::class)->upload(Files::pdf(), 'annual.pdf', '', '1', null, $title);
}

it('shows a non-image in the grid with a kind icon and its title, not a broken thumbnail', function (): void {
    $pdf = pdfAsset();
    $image = Images::upload();

    $html = Livewire::actingAs(User::make())->test(MediaLibraryPage::class)->assertOk()->assertSee('Annual report 2025')->html();

    expect($html)->toContain('annual.pdf')
        ->and($html)->not->toContain('/media/'.$pdf->uuid.'/render')
        ->and($html)->toContain('/media/'.$image->uuid.'/render');
});

it('flags a non-image with no title instead of alt text', function (): void {
    $pdf = pdfAsset();
    $pdf->forceFill(['title' => null])->save();

    Livewire::actingAs(User::make())->test(MediaLibraryPage::class)->assertSee(__('media-library::media.no_title'))->assertDontSee(__('media-library::media.no_alt'));
});

it('finds a document by its title in the grid search', function (): void {
    pdfAsset('Circular on admissions');
    Images::upload('A campus');

    Livewire::actingAs(User::make())->test(MediaLibraryPage::class)->set('search', 'admissions')->assertSee('annual.pdf')->assertDontSee('split.png');
});

it('uploads a document through the header action with a title', function (): void {
    Gate::define('media.manage', static fn (): bool => true);

    Livewire::actingAs(User::make())
        ->test(MediaLibraryPage::class)
        ->callAction('upload', [
            'file' => UploadedFile::fake()->createWithContent('minutes.pdf', Files::pdf()),
            'title' => 'Minutes of March',
        ])
        ->assertHasNoActionErrors();

    $asset = MediaAsset::query()->sole();

    expect($asset->kind)->toBe(MediaKind::Document)->and($asset->title)->toBe('Minutes of March')->and($asset->alt_text)->toBeNull();
});

it('reports a missing title from the repository when a document is uploaded without one', function (): void {
    Gate::define('media.manage', static fn (): bool => true);

    Livewire::actingAs(User::make())
        ->test(MediaLibraryPage::class)
        ->callAction('upload', ['file' => UploadedFile::fake()->createWithContent('minutes.pdf', Files::pdf())]);

    expect(MediaAsset::query()->count())->toBe(0);
});

it('renders the asset page of a non-image without a focal point, and edits its title', function (): void {
    Gate::define('media.manage', static fn (): bool => true);
    $pdf = pdfAsset();

    $test = Livewire::actingAs(User::make())->test(MediaAssetPage::class, ['asset' => $pdf->uuid])
        ->assertOk()
        ->assertSee('Annual report 2025')
        ->assertDontSee(__('media-library::media.detail.focal_heading'))
        ->assertDontSee(__('media-library::media.detail.fact_alt'));

    expect($test->html())->not->toContain('/render');

    $test->callAction('editDetails', ['title' => 'Annual report 2026'])->assertHasNoActionErrors();

    expect($pdf->refresh()->title)->toBe('Annual report 2026');
});

it('keeps the focal point and alt text on the asset page of an image', function (): void {
    Livewire::actingAs(User::make())->test(MediaAssetPage::class, ['asset' => Images::upload()->uuid])
        ->assertSee(__('media-library::media.detail.focal_heading'))
        ->assertSee(__('media-library::media.detail.fact_alt'));
});

it('refuses, with a notification, to replace a pdf with an image from the asset page', function (): void {
    Gate::define('media.manage', static fn (): bool => true);
    $pdf = pdfAsset();

    Livewire::actingAs(User::make())->test(MediaAssetPage::class, ['asset' => $pdf->uuid])
        ->callAction('replaceFile', ['file' => UploadedFile::fake()->createWithContent('a.png', Images::splitPng())]);

    expect($pdf->refresh()->mime_type)->toBe('application/pdf')->and($pdf->versions)->toHaveCount(1);
});

it('keeps the picker to images', function (): void {
    pdfAsset();
    $image = Images::upload('A picture');

    $field = Livewire::actingAs(User::make())->test(PickerForm::class)->instance()->getSchema('form')?->getComponent('hero_id');
    assert($field instanceof MediaPicker);

    expect(array_keys($field->getOptions()))->toBe([$image->id])
        ->and(array_keys($field->getSearchResults('annual')))->toBe([])
        ->and(array_keys($field->getSearchResults('split')))->toBe([$image->id]);
});

it('gives the thumbnail column nothing for a non-image', function (): void {
    expect(MediaThumbnailColumn::make('hero')->getImageUrl((string) pdfAsset()->id))->toBeNull()
        ->and(MediaThumbnailColumn::make('hero')->getImageUrl((string) Images::upload()->id))->toContain('/render');
});

it('builds the upload form for the kinds it is given', function (): void {
    $names = static fn (array $components): array => array_map(static fn ($c): string => $c->getName(), $components);

    expect($names(MediaForms::upload([MediaKind::Image])))->toBe(['file', 'alt_text', 'license_expires_at', 'tags'])
        ->and($names(MediaForms::upload()))->toBe(['file', 'title', 'alt_text', 'license_expires_at', 'tags'])
        ->and($names(MediaForms::details(false)))->toBe(['title', 'alt_text', 'license_expires_at'])
        ->and($names(MediaForms::details()))->toBe(['alt_text', 'license_expires_at']);

    $file = MediaForms::upload([MediaKind::Image])[0];
    $any = MediaForms::upload()[0];
    assert($file instanceof FileUpload && $any instanceof FileUpload);

    expect($file->getAcceptedFileTypes())->toBe(['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'])
        ->and($any->getAcceptedFileTypes())->toContain('application/pdf', 'video/mp4', 'image/png')
        ->and($any->getMaxSize())->toBe(512000);
});

it('treats no file, or an image, as an image upload, and anything else as not', function (): void {
    $pdf = UploadedFile::fake()->createWithContent('a.pdf', Files::pdf());
    $png = UploadedFile::fake()->createWithContent('a.png', Images::splitPng());

    expect(MediaForms::isImageUpload(null))->toBeTrue()
        ->and(MediaForms::isImageUpload($png))->toBeTrue()
        ->and(MediaForms::isImageUpload(['uuid-1' => $png]))->toBeTrue()
        ->and(MediaForms::isImageUpload($pdf))->toBeFalse()
        ->and(MediaForms::isImageUpload(['uuid-1' => $pdf]))->toBeFalse();
});

it('turns a file of the wrong type away in the form with one short sentence, not a list of every accepted type', function (): void {
    Gate::define('media.manage', static fn (): bool => true);

    $page = Livewire::actingAs(User::make())
        ->test(MediaLibraryPage::class)
        ->callAction('upload', [
            'file' => UploadedFile::fake()->createWithContent('quarterly-report.pdf', '<html><script>alert(1)</script></html>')->mimeType('text/html'),
            'title' => 'Quarterly report',
        ])
        ->assertHasActionErrors(['file']);

    $messages = implode(' ', array_merge(...array_values($page->errors()->toArray())));

    expect($messages)->toContain('This type of file is not accepted')->not->toContain('application/vnd')->and(MediaAsset::query()->count())->toBe(0);
});
