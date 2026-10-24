<?php

declare(strict_types=1);

use Banyan\FilamentMediaLibrary\Forms\MediaPicker;
use Banyan\FilamentMediaLibrary\MediaLibraryPlugin;
use Banyan\FilamentMediaLibrary\Pages\MediaAssetPage;
use Banyan\FilamentMediaLibrary\Pages\MediaLibraryPage;
use Banyan\FilamentMediaLibrary\Tests\Support\CustomAssetPage;
use Banyan\FilamentMediaLibrary\Tests\Support\CustomLibraryPage;
use Banyan\FilamentMediaLibrary\Tests\Support\Images;
use Banyan\FilamentMediaLibrary\Tests\Support\PickerForm;
use Banyan\FilamentMediaLibrary\Tests\Support\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

covers(MediaLibraryPlugin::class);

beforeEach(function (): void {
    view()->addNamespace('media-library-tests', __DIR__.'/Support/views');
});

/*
 * Two panels on one app ("admin" with the default pages, "staff" with its own
 * page classes). They share one library — the same tables, disk and assets —
 * and each panel keeps its own routes, links and page classes.
 */

it('gives each panel its own routes for the library pages', function (): void {
    expect(Filament::getPanel('admin')->getPages())->toContain(MediaLibraryPage::class, MediaAssetPage::class)
        ->and(Filament::getPanel('staff')->getPages())->toContain(CustomLibraryPage::class, CustomAssetPage::class)
        ->and(route('filament.admin.pages.media'))->toEndWith('/admin/media')
        ->and(route('filament.staff.pages.pictures'))->toEndWith('/staff/pictures');
});

it('links within the current panel, using that panel\'s page classes', function (): void {
    $asset = Images::upload();

    Filament::setCurrentPanel('admin');
    $admin = MediaLibraryPlugin::assetPageClass()::getUrl(['asset' => $asset->uuid]);

    Filament::setCurrentPanel('staff');
    $staff = MediaLibraryPlugin::assetPageClass()::getUrl(['asset' => $asset->uuid]);

    expect($admin)->toEndWith('/admin/media/'.$asset->uuid)
        ->and($staff)->toEndWith('/staff/pictures/'.$asset->uuid)
        ->and(MediaLibraryPlugin::libraryPageClass())->toBe(CustomLibraryPage::class);
});

it('shows the same shared library in both panels', function (): void {
    $asset = Images::upload('The campus at dusk');
    $user = User::make();

    Filament::setCurrentPanel('admin');
    Livewire::actingAs($user)->test(MediaLibraryPage::class)->assertOk()->assertSee($asset->filename);

    Filament::setCurrentPanel('staff');
    Livewire::actingAs($user)->test(CustomLibraryPage::class)->assertOk()->assertSee($asset->filename);
    Livewire::actingAs($user)->test(CustomAssetPage::class, ['asset' => $asset->uuid])->assertOk();
});

it('lets a form in either panel pick from the same library', function (): void {
    $asset = Images::upload();

    foreach (['admin', 'staff'] as $panel) {
        Filament::setCurrentPanel($panel);
        $field = Livewire::actingAs(User::make())->test(PickerForm::class)->instance()->getSchema('form')?->getComponent('hero_id');

        expect($field)->toBeInstanceOf(MediaPicker::class)
            ->and(array_keys($field->getOptions()))->toContain($asset->id);
    }
});

it('serves the same image URL whichever panel builds it', function (): void {
    $asset = Images::upload();

    Filament::setCurrentPanel('admin');
    $fromAdmin = $asset->url(320, 240, 'crop');
    Filament::setCurrentPanel('staff');

    expect($asset->url(320, 240, 'crop'))->toBe($fromAdmin)
        ->and($fromAdmin)->not->toContain('/admin/')->not->toContain('/staff/');
});
