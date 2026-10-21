<?php

declare(strict_types=1);

use Banyan\FilamentMediaLibrary\Admin\MediaAssetPage;
use Banyan\FilamentMediaLibrary\Admin\MediaLibraryPage;
use Banyan\FilamentMediaLibrary\MediaLibraryPlugin;
use Banyan\FilamentMediaLibrary\Models\MediaAsset;
use Banyan\FilamentMediaLibrary\Tests\Support\Images;
use Banyan\FilamentMediaLibrary\Tests\Support\User;
use Banyan\FilamentMediaLibrary\Usage\UsageRecorder;
use Filament\Facades\Filament;
use Filament\Support\Assets\Css;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

covers(MediaLibraryPlugin::class, MediaLibraryPage::class, MediaAssetPage::class);

it('adds both pages to the panel that installs the plugin', function (): void {
    expect(Filament::getPanel('admin')->getPages())->toContain(MediaLibraryPage::class, MediaAssetPage::class)
        ->and(MediaLibraryPlugin::make()->getId())->toBe('media-library')
        ->and(MediaLibraryPage::getUrl())->toEndWith('/admin/media');
});

it('takes its navigation from config', function (): void {
    config()->set('media-library.navigation', ['register' => false, 'group' => 'Content', 'sort' => 7]);

    expect(MediaLibraryPage::shouldRegisterNavigation())->toBeFalse()
        ->and(MediaLibraryPage::getNavigationGroup())->toBe('Content')
        ->and(MediaLibraryPage::getNavigationSort())->toBe(7);
});

it('shows the library to a signed-in user without offering uploads', function (): void {
    $asset = Images::upload('A red and blue split');

    Livewire::actingAs(User::make())
        ->test(MediaLibraryPage::class)
        ->assertOk()
        ->assertSee($asset->filename)
        ->assertSeeHtml('aria-label="'.__('media-library::media.actions.open_named', ['name' => $asset->filename]).'"')
        ->assertActionHidden('upload');
});

it('uploads through the header action for a user who may manage', function (): void {
    Gate::define('media.manage', static fn (): bool => true);

    Livewire::actingAs(User::make())
        ->test(MediaLibraryPage::class)
        ->callAction('upload', [
            'file' => UploadedFile::fake()->createWithContent('campus.png', Images::splitPng()),
            'alt_text' => 'The campus at dusk',
            'tags' => 'Campus, Dusk',
        ])
        ->assertHasNoActionErrors();

    $asset = MediaAsset::query()->sole();

    expect($asset->alt_text)->toBe('The campus at dusk')
        ->and($asset->tags->pluck('name')->sort()->values()->all())->toBe(['campus', 'dusk']);
});

it('lists where an asset is used on its page', function (): void {
    $asset = Images::upload();
    $owner = User::make();
    app(UsageRecorder::class)->sync($owner, 'avatar', [$asset->id]);

    Livewire::actingAs($owner)
        ->test(MediaAssetPage::class, ['asset' => $asset->uuid])
        ->assertOk()
        ->assertSee('User #'.$owner->id)
        ->assertSee('avatar')
        ->assertSee(__('media-library::media.detail.usage_col_record'));
});

it('answers 404 for an asset that does not exist', function (): void {
    Livewire::actingAs(User::make())
        ->test(MediaAssetPage::class, ['asset' => '00000000-0000-4000-8000-000000000000'])
        ->assertStatus(404);
});

it('ships every library string in all three languages', function (): void {
    $flatten = static function (array $lines, string $prefix = '') use (&$flatten): array {
        $keys = [];
        foreach ($lines as $key => $value) {
            $keys = [...$keys, ...(is_array($value) ? $flatten($value, $prefix.$key.'.') : [$prefix.$key])];
        }

        return $keys;
    };
    $english = $flatten(require dirname(__DIR__).'/resources/lang/en/media.php');

    foreach (['zh-Hant', 'zh-Hans'] as $locale) {
        expect($flatten(require dirname(__DIR__).'/resources/lang/'.$locale.'/media.php'))->toBe($english);
    }
});

it('registers its stylesheet as a Filament asset', function (): void {
    $style = collect(FilamentAsset::getStyles())
        ->first(fn (Css $css): bool => $css->getId() === 'media-library');

    expect($style)->not->toBeNull()
        ->and($style->getPackage())->toBe('banyancms/filament-media-library')
        ->and(file_exists($style->getPath()))->toBeTrue();
});
