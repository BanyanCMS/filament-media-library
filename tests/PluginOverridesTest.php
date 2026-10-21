<?php

declare(strict_types=1);

use Banyan\FilamentMediaLibrary\Admin\MediaAssetPage;
use Banyan\FilamentMediaLibrary\Admin\MediaLibraryPage;
use Banyan\FilamentMediaLibrary\MediaLibraryPlugin;
use Banyan\FilamentMediaLibrary\MediaLibraryServiceProvider;
use Banyan\FilamentMediaLibrary\Tests\Support\CustomAssetPage;
use Banyan\FilamentMediaLibrary\Tests\Support\CustomLibraryPage;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Support\ServiceProvider;

covers(MediaLibraryPlugin::class);

it('registers the default pages and links between them', function (): void {
    expect(MediaLibraryPlugin::libraryPageClass())->toBe(MediaLibraryPage::class)
        ->and(MediaLibraryPlugin::assetPageClass())->toBe(MediaAssetPage::class);
});

it('registers swapped pages and makes the links follow them', function (): void {
    $panel = Panel::make()->id('custom')->path('custom')->plugin(
        MediaLibraryPlugin::make()->libraryPage(CustomLibraryPage::class)->assetPage(CustomAssetPage::class),
    );
    Filament::setCurrentPanel($panel);

    expect($panel->getPages())->toContain(CustomLibraryPage::class, CustomAssetPage::class)
        ->and($panel->getPages())->not->toContain(MediaLibraryPage::class)
        ->and(MediaLibraryPlugin::libraryPageClass())->toBe(CustomLibraryPage::class)
        ->and(MediaLibraryPlugin::assetPageClass())->toBe(CustomAssetPage::class);
});

it('falls back to the default pages on a panel without the plugin', function (): void {
    Filament::setCurrentPanel(Panel::make()->id('bare')->path('bare'));

    expect(MediaLibraryPlugin::libraryPageClass())->toBe(MediaLibraryPage::class)
        ->and(MediaLibraryPlugin::assetPageClass())->toBe(MediaAssetPage::class);
});

it('refuses a page that does not extend the library page', function (): void {
    expect(fn () => MediaLibraryPlugin::make()->libraryPage(MediaAssetPage::class))->toThrow(InvalidArgumentException::class)
        ->and(fn () => MediaLibraryPlugin::make()->assetPage(MediaLibraryPage::class))->toThrow(InvalidArgumentException::class);
});

it('publishes its views for overriding', function (): void {
    $paths = ServiceProvider::pathsToPublish(MediaLibraryServiceProvider::class, 'media-library-views');

    expect(array_values($paths))->toBe([resource_path('views/vendor/media-library')])
        ->and(array_keys($paths)[0])->toEndWith('resources/views');
});
