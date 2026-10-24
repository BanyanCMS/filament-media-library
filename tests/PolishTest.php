<?php

declare(strict_types=1);

use Banyan\FilamentMediaLibrary\Events\AssetReplaced;
use Banyan\FilamentMediaLibrary\Events\AssetUploaded;
use Banyan\FilamentMediaLibrary\Governance\MediaGovernanceService;
use Banyan\FilamentMediaLibrary\Models\MediaAsset;
use Banyan\FilamentMediaLibrary\Pages\MediaAssetPage;
use Banyan\FilamentMediaLibrary\Pages\MediaLibraryPage;
use Banyan\FilamentMediaLibrary\Rendering\RenditionService;
use Banyan\FilamentMediaLibrary\Storage\MediaRepository;
use Banyan\FilamentMediaLibrary\Tests\Support\Images;
use Banyan\FilamentMediaLibrary\Tests\Support\User;
use Banyan\FilamentMediaLibrary\Usage\UsageRecorder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

covers(MediaRepository::class, MediaLibraryPage::class, MediaAssetPage::class);

/** Insert bare asset rows: the grid only needs rows, not files. */
function seedAssetRows(int $count): void
{
    $now = now();
    $rows = [];

    for ($i = 1; $i <= $count; $i++) {
        $rows[] = [
            'uuid' => sprintf('00000000-0000-4000-8000-%012d', $i), 'disk' => 'media-lib-test', 'path' => "p/{$i}.png",
            'filename' => "row-{$i}.png", 'mime_type' => 'image/png', 'size_bytes' => 1, 'width' => 1, 'height' => 1,
            'alt_text' => "Row {$i}", 'focal_x' => 0.5, 'focal_y' => 0.5, 'created_at' => $now, 'updated_at' => $now,
        ];
    }

    DB::table('lib_assets')->insert($rows);
}

it('pages the grid and loads more on request', function (): void {
    seedAssetRows(MediaLibraryPage::PAGE_SIZE + 5);

    $page = Livewire::actingAs(User::make())->test(MediaLibraryPage::class);

    expect($page->instance()->assets())->toHaveCount(MediaLibraryPage::PAGE_SIZE)
        ->and($page->instance()->totalAssets())->toBe(MediaLibraryPage::PAGE_SIZE + 5);

    $page->assertSee('Showing 120 of 125 assets')->assertSee('Load more');

    $page->call('loadMore');

    expect($page->instance()->assets())->toHaveCount(MediaLibraryPage::PAGE_SIZE + 5);
    $page->assertSee('125 assets')->assertDontSee('Load more');

    $page->set('search', 'Row 1');

    expect($page->get('shown'))->toBe(MediaLibraryPage::PAGE_SIZE);
});

it('does not offer Delete on the asset page while the asset is used', function (): void {
    Gate::define('media.manage', static fn (): bool => true);
    $used = Images::upload();
    $unused = Images::upload();
    app(UsageRecorder::class)->sync(User::make('Owner'), 'avatar', [$used->id]);

    Livewire::actingAs(User::make())->test(MediaAssetPage::class, ['asset' => $used->uuid])
        ->assertActionHidden(TestAction::make('deleteAsset'));
    Livewire::actingAs(User::make('Other'))->test(MediaAssetPage::class, ['asset' => $unused->uuid])
        ->assertActionVisible(TestAction::make('deleteAsset'));
});

it('numbers versions as an editor counts them and shows readable sizes', function (): void {
    $asset = Images::upload();
    app(MediaGovernanceService::class)->replace($asset, Images::splitPng(800, 400), 'bigger.png', '1');

    $html = Livewire::actingAs(User::make())->test(MediaAssetPage::class, ['asset' => $asset->uuid])->html();

    expect($html)->toContain('#2')->toContain('#1')->toMatch('/\d+(\.\d)? KB/');
});

it('removes the written file when a listener rolls the upload back', function (): void {
    Event::listen(AssetUploaded::class, static fn () => throw new RuntimeException('refused'));

    expect(fn () => Images::upload())->toThrow(RuntimeException::class);

    expect(Storage::disk('media-lib-test')->allFiles())->toBe([]);
});

it('keeps the old file when a replacement is rolled back', function (): void {
    $asset = Images::upload();
    $before = Storage::disk('media-lib-test')->allFiles();
    Event::listen(AssetReplaced::class, static fn () => throw new RuntimeException('refused'));

    expect(fn () => app(MediaGovernanceService::class)->replace($asset, Images::splitPng(), 'new.png', '1'))
        ->toThrow(RuntimeException::class);

    expect(Storage::disk('media-lib-test')->allFiles())->toBe($before);
});

it('keeps an asset on the disk it was uploaded to after the default disk changes', function (): void {
    $asset = Images::upload();
    $old = Storage::disk('media-lib-test');
    $new = Storage::fake('media-lib-new');

    config()->set('media-library.disk', 'media-lib-new');
    app()->forgetInstance(MediaRepository::class);
    app()->forgetInstance(RenditionService::class);
    app()->forgetInstance(MediaGovernanceService::class);

    app(MediaGovernanceService::class)->replace($asset, Images::splitPng(), 'again.png', '1');
    $asset->refresh();

    expect($asset->disk)->toBe('media-lib-test')
        ->and($old->exists($asset->path))->toBeTrue()
        ->and(app(RenditionService::class)->render($asset, app(RenditionService::class)->spec(50, 50, 'crop')))->not->toBeNull()
        ->and($new->allFiles())->toBe([]);

    app(MediaGovernanceService::class)->delete($asset, '1');

    expect($old->allFiles())->toBe([])
        ->and(MediaAsset::query()->count())->toBe(0);

    $fresh = Images::upload();

    expect($fresh->disk)->toBe('media-lib-new')->and($new->exists($fresh->path))->toBeTrue();
});
