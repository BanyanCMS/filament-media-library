<?php

declare(strict_types=1);

use Banyan\FilamentMediaLibrary\Admin\MediaAssetPage;
use Banyan\FilamentMediaLibrary\Admin\MediaLibraryPage;
use Banyan\FilamentMediaLibrary\Folders\MediaFolderService;
use Banyan\FilamentMediaLibrary\Governance\MediaGovernanceService;
use Banyan\FilamentMediaLibrary\Models\MediaAsset;
use Banyan\FilamentMediaLibrary\Models\MediaFolder;
use Banyan\FilamentMediaLibrary\Tests\Support\Images;
use Banyan\FilamentMediaLibrary\Tests\Support\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

covers(MediaLibraryPage::class, MediaAssetPage::class);

afterEach(function (): void {
    MediaLibraryPage::currentSiteUsing(null);
});

function makeFolder(string $name, ?MediaFolder $parent = null, ?int $site = null): MediaFolder
{
    return app(MediaFolderService::class)->create($parent, $name, '1', $site);
}

/** An image whose filename is `$name.png`, so a page's HTML tells one asset from another. */
function imageFile(string $name): MediaAsset
{
    return app(MediaGovernanceService::class)->upload(Images::splitPng(), $name.'.png', 'image/png', 'Alt for '.$name, '1');
}

function fileIn(MediaFolder $folder, string $name): MediaAsset
{
    $asset = imageFile($name);
    app(MediaFolderService::class)->moveAssets([$asset], $folder, '1');

    return $asset;
}

function folderPageManager(): User
{
    static $count = 0;
    Gate::define('media.manage', static fn (): bool => true);

    return User::make('Manager'.++$count);
}

it('renders the folder tree with tree roles and expanded and selected state', function (): void {
    $reports = makeFolder('Reports');
    $year = makeFolder('2025', $reports);
    makeFolder('Press');

    Livewire::actingAs(User::make())
        ->test(MediaLibraryPage::class)
        ->assertSeeHtml('role="tree"')
        ->assertSeeHtml('role="treeitem"')
        ->assertSeeHtml('role="group"')
        ->assertSeeHtml('aria-selected="true"')
        ->assertSeeHtml('aria-expanded="false"')
        ->assertSee('Reports')
        ->assertSee('Press')
        // Collapsed: the sub-folder is not in the tree yet, and its tile is not shown at the root either.
        ->assertDontSee('2025')
        ->call('toggleFolder', $reports->uuid)
        ->assertSeeHtml('aria-expanded="true"')
        ->assertSee('2025')
        ->call('openFolder', $year->uuid)
        ->assertSet('currentFolder', $year->uuid)
        ->assertSeeHtml('aria-current="true"')
        ->assertSeeHtml('aria-current="page"');
});

it('shows only the open folder\'s own assets and sub-folders, and breadcrumbs above them', function (): void {
    $reports = makeFolder('Reports');
    $year = makeFolder('2025', $reports);
    $other = makeFolder('Press');
    $rootAsset = imageFile('at-the-root');
    $inReports = fileIn($reports, 'annual-figures');
    $inYear = fileIn($year, 'year-figures');
    $inOther = fileIn($other, 'press-photo');

    $page = Livewire::actingAs(User::make())->test(MediaLibraryPage::class);

    // The root: unfiled assets only, and the top-level folders as tiles.
    $page->assertSee('at-the-root.png')->assertSee('Open folder Reports')->assertSee('Open folder Press')
        ->assertDontSee('annual-figures.png')->assertDontSee('year-figures.png');

    $page->call('openFolder', $reports->uuid)
        ->assertSee('Open folder 2025')
        ->assertSeeHtml('aria-label="Folder path"')
        ->assertSee('All files')
        ->assertDontSee('at-the-root.png')
        ->assertDontSee('press-photo.png')
        ->assertDontSee('year-figures.png');

    expect(array_map(static fn (MediaAsset $a): int => $a->id, $page->instance()->assets()))->toBe([$inReports->id]);

    $page->call('openFolder', $year->uuid);
    expect(array_map(static fn (MediaAsset $a): int => $a->id, $page->instance()->assets()))->toBe([$inYear->id])
        ->and(array_map(static fn (MediaFolder $f): string => $f->name, $page->instance()->breadcrumbs()))->toBe(['Reports', '2025']);

    $page->call('openFolder')->assertSet('currentFolder', null);
    expect(array_map(static fn (MediaAsset $a): int => $a->id, $page->instance()->assets()))->toBe([$rootAsset->id])
        ->and($inOther->folder_id)->toBe($other->id);
});

it('counts the contents of a folder tile', function (): void {
    $reports = makeFolder('Reports');
    makeFolder('2025', $reports);
    fileIn($reports, 'one');
    fileIn($reports, 'two');

    Livewire::actingAs(User::make())->test(MediaLibraryPage::class)->assertSee('3 items');
});

it('says so when a folder is empty', function (): void {
    $empty = makeFolder('Nothing yet');

    Livewire::actingAs(User::make())
        ->test(MediaLibraryPage::class)
        ->call('openFolder', $empty->uuid)
        ->assertSee('This folder is empty.')
        ->assertDontSee('No assets yet');
});

it('opens the folder named in the query string and falls back to the root for an unknown one', function (): void {
    $reports = makeFolder('Reports');
    fileIn($reports, 'annual-figures');

    Livewire::withQueryParams(['folder' => $reports->uuid])->actingAs(User::make())
        ->test(MediaLibraryPage::class)
        ->assertSee('annual-figures.png');

    $page = Livewire::withQueryParams(['folder' => '00000000-0000-4000-8000-000000000000'])->actingAs(User::make())
        ->test(MediaLibraryPage::class);

    expect($page->instance()->currentFolder())->toBeNull();

    $page->call('openFolder', 'not-a-folder')->assertSet('currentFolder', null);
});

it('uploads into the open folder', function (): void {
    $reports = makeFolder('Reports');

    Livewire::actingAs(folderPageManager())
        ->test(MediaLibraryPage::class)
        ->call('openFolder', $reports->uuid)
        ->callAction('upload', [
            'file' => UploadedFile::fake()->createWithContent('campus.png', Images::splitPng()),
            'alt_text' => 'The campus at dusk',
        ])
        ->assertHasNoActionErrors();

    expect(MediaAsset::query()->sole()->folder_id)->toBe($reports->id);
});

it('searches the open folder and below by default, and the whole library on request', function (): void {
    $reports = makeFolder('Reports');
    $year = makeFolder('2025', $reports);
    $press = makeFolder('Press');
    fileIn($reports, 'budget-in-reports');
    fileIn($year, 'budget-in-year');
    fileIn($press, 'budget-in-press');
    imageFile('budget-at-root');

    $page = Livewire::actingAs(User::make())->test(MediaLibraryPage::class)
        ->call('openFolder', $reports->uuid)
        ->set('search', 'budget')
        ->assertSee('budget-in-reports.png')->assertSee('budget-in-year.png')
        ->assertDontSee('budget-in-press.png')->assertDontSee('budget-at-root.png')
        // Searching shows files, not folder tiles.
        ->assertDontSee('Open folder 2025')
        ->assertSee('Search the whole library');

    $page->set('wholeLibrary', true)
        ->assertSee('budget-in-press.png')->assertSee('budget-at-root.png')
        ->assertSee('budget-in-reports.png');

    $page->call('resetFilters')->assertSet('wholeLibrary', false)->assertSet('search', '');
});

it('offers the whole-library toggle only inside a folder', function (): void {
    makeFolder('Reports');

    Livewire::actingAs(User::make())->test(MediaLibraryPage::class)
        ->set('search', 'x')
        ->assertDontSee('Search the whole library');
});

it('creates, renames, moves and deletes a folder from the header actions', function (): void {
    $reports = makeFolder('Reports');
    $archive = makeFolder('Archive');

    $page = Livewire::actingAs(folderPageManager())->test(MediaLibraryPage::class)
        ->callAction('newFolder', ['name' => 'Minutes'])
        ->assertHasNoActionErrors();

    $minutes = MediaFolder::query()->where('name', 'Minutes')->sole();
    expect($minutes->parent_id)->toBeNull()
        ->and($minutes->created_by)->not->toBeNull();

    $page->call('openFolder', $reports->uuid)
        ->callAction('newFolder', ['name' => '2025']);

    $year = MediaFolder::query()->where('name', '2025')->sole();
    expect($year->parent_id)->toBe($reports->id);

    $page->call('openFolder', $year->uuid)
        ->callAction('renameFolder', ['name' => '2026'])
        ->assertHasNoActionErrors();
    expect($year->refresh()->name)->toBe('2026');

    $page->callAction('moveFolder', ['destination' => $archive->uuid]);
    expect($year->refresh()->parent_id)->toBe($archive->id);

    $page->callAction('deleteFolder', ['move_contents' => false])
        ->assertSet('currentFolder', $archive->uuid);
    expect(MediaFolder::query()->whereKey($year->id)->exists())->toBeFalse();
});

it('tags a new top-level folder with the current site when the host supplies one', function (): void {
    MediaLibraryPage::currentSiteUsing(static fn (): int => 12);

    Livewire::actingAs(folderPageManager())->test(MediaLibraryPage::class)
        ->callAction('newFolder', ['name' => 'HIVE']);
    $top = MediaFolder::query()->where('name', 'HIVE')->sole();

    Livewire::actingAs(folderPageManager())->test(MediaLibraryPage::class)
        ->call('openFolder', $top->uuid)
        ->callAction('newFolder', ['name' => 'Press']);

    expect($top->site_id)->toBe(12)
        ->and(MediaFolder::query()->where('name', 'Press')->sole()->site_id)->toBeNull();
});

it('lists the current site\'s top-level folders first', function (): void {
    makeFolder('Alpha');
    makeFolder('Zulu', null, 7);
    makeFolder('Mike', null, 7);
    makeFolder('Bravo', null, 8);

    $order = static fn (MediaLibraryPage $page): array => array_map(static fn ($node): string => $node->folder->name, $page->folderTree());
    $tiles = static fn (MediaLibraryPage $page): array => array_map(static fn (MediaFolder $f): string => $f->name, $page->subfolders());

    $page = Livewire::actingAs(User::make())->test(MediaLibraryPage::class)->instance();
    expect($order($page))->toBe(['Alpha', 'Bravo', 'Mike', 'Zulu']);

    MediaLibraryPage::currentSiteUsing(static fn (): int => 7);

    $page = Livewire::actingAs(User::make())->test(MediaLibraryPage::class)->instance();
    expect($order($page))->toBe(['Mike', 'Zulu', 'Alpha', 'Bravo'])
        ->and($tiles($page))->toBe(['Mike', 'Zulu', 'Alpha', 'Bravo']);
});

it('shows the surfaced error when a folder action is refused', function (): void {
    makeFolder('Reports');

    Livewire::actingAs(folderPageManager())->test(MediaLibraryPage::class)
        ->callAction('newFolder', ['name' => 'reports'])
        ->assertNotified('The folder could not be created.');

    expect(MediaFolder::query()->count())->toBe(1);
});

it('refuses to delete a non-empty folder unless asked to lift its contents first', function (): void {
    $top = makeFolder('Top');
    $doomed = makeFolder('Doomed', $top);
    $asset = fileIn($doomed, 'inside');

    $page = Livewire::actingAs(folderPageManager())->test(MediaLibraryPage::class)
        ->call('openFolder', $doomed->uuid)
        ->callAction('deleteFolder', ['move_contents' => false])
        ->assertNotified('The folder could not be deleted.');

    expect(MediaFolder::query()->whereKey($doomed->id)->exists())->toBeTrue()
        ->and($asset->refresh()->folder_id)->toBe($doomed->id);

    $page->callAction('deleteFolder', ['move_contents' => true])->assertSet('currentFolder', $top->uuid);

    expect(MediaFolder::query()->whereKey($doomed->id)->exists())->toBeFalse()
        ->and($asset->refresh()->folder_id)->toBe($top->id);
});

it('moves ticked assets in bulk and clears the selection', function (): void {
    $target = makeFolder('Target');
    $one = Images::upload('One');
    $two = Images::upload('Two');
    $three = Images::upload('Three');

    Livewire::actingAs(folderPageManager())->test(MediaLibraryPage::class)
        ->assertSeeHtml('wire:model.live="selected"')
        ->set('selected', [(string) $one->id, (string) $two->id])
        ->assertSee('2 files selected')
        ->callAction('moveSelected', ['destination' => $target->uuid])
        ->assertHasNoActionErrors()
        ->assertSet('selected', []);

    expect($one->refresh()->folder_id)->toBe($target->id)
        ->and($two->refresh()->folder_id)->toBe($target->id)
        ->and($three->refresh()->folder_id)->toBeNull();

    Livewire::actingAs(folderPageManager())->test(MediaLibraryPage::class)
        ->call('openFolder', $target->uuid)
        ->set('selected', [$one->id])
        ->callAction('moveSelected', ['destination' => null]);

    expect($one->refresh()->folder_id)->toBeNull();
});

it('does not file assets into a destination that no longer exists', function (): void {
    $asset = Images::upload('One');

    Livewire::actingAs(folderPageManager())->test(MediaLibraryPage::class)
        ->set('selected', [$asset->id])
        ->callAction('moveSelected', ['destination' => '00000000-0000-4000-8000-000000000000'])
        ->assertHasActionErrors(['destination']);

    expect($asset->refresh()->folder_id)->toBeNull();
});

it('keeps checkboxes and every folder action from people who cannot manage', function (): void {
    $reports = makeFolder('Reports');
    Images::upload('One');

    $page = Livewire::actingAs(User::make())->test(MediaLibraryPage::class)
        ->assertDontSeeHtml('wire:model.live="selected"')
        ->assertActionHidden('newFolder')
        ->call('openFolder', $reports->uuid)
        ->assertActionHidden('renameFolder')
        ->assertActionHidden('moveFolder')
        ->assertActionHidden('deleteFolder')
        ->assertActionHidden('moveSelected');

    // A hidden action called anyway must still do nothing: the handler re-checks the gate.
    $page->call('mountAction', 'newFolder');
    expect($page->instance()->canManageFolders())->toBeFalse()
        ->and(MediaFolder::query()->count())->toBe(1);
});

it('moves a single asset from its own page and shows its folder', function (): void {
    $reports = makeFolder('Reports');
    $year = makeFolder('2025', $reports);
    $asset = Images::upload('One');

    $page = Livewire::actingAs(folderPageManager())->test(MediaAssetPage::class, ['asset' => $asset->uuid])
        ->assertSee('Library root')
        ->callAction('moveAsset', ['destination' => $year->uuid])
        ->assertHasNoActionErrors();

    expect($asset->refresh()->folder_id)->toBe($year->id);

    $page->assertSee('Reports / 2025')
        ->callAction('moveAsset', ['destination' => null]);

    expect($asset->refresh()->folder_id)->toBeNull();
});

it('hides the asset page move action from people who cannot manage and fails on an unknown folder', function (): void {
    $asset = Images::upload('One');

    Livewire::actingAs(User::make())->test(MediaAssetPage::class, ['asset' => $asset->uuid])
        ->assertActionHidden('moveAsset');

    Livewire::actingAs(folderPageManager())->test(MediaAssetPage::class, ['asset' => $asset->uuid])
        ->callAction('moveAsset', ['destination' => '00000000-0000-4000-8000-000000000000'])
        ->assertHasActionErrors(['destination']);

    expect($asset->refresh()->folder_id)->toBeNull();
});

it('hides every folder surface and treats everything as the root when folders are disabled', function (): void {
    config()->set('media-library.folders.enabled', false);
    $reports = makeFolder('Reports');
    $inside = fileIn($reports, 'hidden-away');
    $loose = imageFile('loose');

    $page = Livewire::actingAs(folderPageManager())->test(MediaLibraryPage::class, [])
        ->assertDontSeeHtml('role="tree"')
        ->assertDontSee('Reports')
        ->assertDontSeeHtml('wire:model.live="selected"')
        ->assertActionHidden('newFolder')
        ->assertSee('hidden-away.png')
        ->assertSee('loose.png')
        ->call('openFolder', $reports->uuid)
        ->assertSet('currentFolder', null);

    expect($page->instance()->canManageFolders())->toBeFalse()
        ->and($page->instance()->currentFolder())->toBeNull();

    $page->callAction('upload', [
        'file' => UploadedFile::fake()->createWithContent('campus.png', Images::splitPng()),
        'alt_text' => 'Lands at the root',
    ]);
    expect(MediaAsset::query()->where('alt_text', 'Lands at the root')->sole()->folder_id)->toBeNull();

    Livewire::actingAs(folderPageManager())->test(MediaAssetPage::class, ['asset' => $inside->uuid])
        ->assertActionHidden('moveAsset')
        ->assertDontSee('Reports');
    expect($loose->folder_id)->toBeNull();
});

it('names a folder tile by every word it shows, so the accessible name contains the visible text', function (): void {
    $reports = makeFolder('Reports');
    makeFolder('2025', $reports);
    fileIn($reports, 'annual-figures');

    // The tile reads "Reports", then its item count: its accessible name must contain both (WCAG 2.5.3).
    Livewire::actingAs(User::make())
        ->test(MediaLibraryPage::class)
        ->assertSeeHtml('aria-label="Open folder Reports 2 items"');
});
