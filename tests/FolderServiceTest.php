<?php

declare(strict_types=1);

use Banyan\FilamentMediaLibrary\Events\AssetMoved;
use Banyan\FilamentMediaLibrary\Events\FolderCreated;
use Banyan\FilamentMediaLibrary\Events\FolderDeleted;
use Banyan\FilamentMediaLibrary\Events\FolderMoved;
use Banyan\FilamentMediaLibrary\Events\FolderRenamed;
use Banyan\FilamentMediaLibrary\Events\MediaEvent;
use Banyan\FilamentMediaLibrary\Events\MediaFolderEvent;
use Banyan\FilamentMediaLibrary\Exceptions\FolderCycle;
use Banyan\FilamentMediaLibrary\Exceptions\FolderNameTaken;
use Banyan\FilamentMediaLibrary\Exceptions\FolderNotEmpty;
use Banyan\FilamentMediaLibrary\Exceptions\FolderTooDeep;
use Banyan\FilamentMediaLibrary\Exceptions\InvalidFolderName;
use Banyan\FilamentMediaLibrary\Folders\MediaFolderService;
use Banyan\FilamentMediaLibrary\Governance\MediaGovernanceService;
use Banyan\FilamentMediaLibrary\Models\MediaAsset;
use Banyan\FilamentMediaLibrary\Models\MediaFolder;
use Banyan\FilamentMediaLibrary\Tests\Support\Images;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

covers(MediaFolderService::class, MediaFolder::class, FolderCreated::class, FolderRenamed::class, FolderMoved::class, FolderDeleted::class, AssetMoved::class);

function folders(): MediaFolderService
{
    return app(MediaFolderService::class);
}

/** Collects every media event dispatched from here on. */
function captureMediaEvents(): object
{
    $bag = new ArrayObject;
    Event::listen(MediaEvent::class, function (MediaEvent $event) use ($bag): void {
        $bag[] = $event;
    });

    return $bag;
}

it('creates folders with a path and a depth', function (): void {
    $reports = folders()->create(null, '  Reports ', '7');
    $year = folders()->create($reports, '2025', '7');

    expect($reports->name)->toBe('Reports')
        ->and($reports->path)->toBe('/'.$reports->id.'/')
        ->and($reports->depth)->toBe(1)
        ->and($reports->owner_id)->toBe(7)
        ->and($reports->access)->toBe('inherit')
        ->and($year->path)->toBe($reports->path.$year->id.'/')
        ->and($year->depth)->toBe(2)
        ->and($year->parent->is($reports))->toBeTrue()
        ->and($reports->children()->count())->toBe(1);
});

it('tags a top-level folder with a site and ignores the tag below the top level', function (): void {
    $top = folders()->create(null, 'HIVE', '1', siteId: 4);
    $child = folders()->create($top, 'Press', '1', siteId: 9);

    expect($top->site_id)->toBe(4)->and($child->site_id)->toBeNull();
});

it('refuses invalid names', function (string $name): void {
    expect(fn () => folders()->create(null, $name, '1'))->toThrow(InvalidFolderName::class)
        ->and(MediaFolder::query()->count())->toBe(0);
})->with([
    'empty' => '',
    'blank' => "  \t ",
    'too long' => str_repeat('x', 121),
    'slash' => 'a/b',
    'control character' => "a\x07b",
    'newline' => "a\nb",
    'invalid utf-8' => "a\xFFb",
]);

it('accepts a name of exactly 120 characters', function (): void {
    expect(folders()->create(null, str_repeat('é', 120), '1')->name)->toHaveLength(120);
});

it('refuses a sibling name that differs only by case, at the root and below', function (): void {
    $root = folders()->create(null, 'Reports', '1');
    folders()->create($root, 'Minutes', '1');

    expect(fn () => folders()->create(null, 'REPORTS', '1'))->toThrow(FolderNameTaken::class)
        ->and(fn () => folders()->create($root, 'minutes', '1'))->toThrow(FolderNameTaken::class)
        // The same name under a different parent is fine.
        ->and(folders()->create(null, 'Minutes', '1')->name)->toBe('Minutes');
});

it('compares names case-insensitively beyond ASCII', function (): void {
    folders()->create(null, 'Écoles', '1');

    expect(fn () => folders()->create(null, 'écoles', '1'))->toThrow(FolderNameTaken::class);
});

it('renames, allows a change of case, and refuses a taken name', function (): void {
    $a = folders()->create(null, 'alpha', '1');
    folders()->create(null, 'Beta', '1');

    expect(folders()->rename($a, 'Alpha', '1')->name)->toBe('Alpha')
        ->and(fn () => folders()->rename($a, 'beta', '1'))->toThrow(FolderNameTaken::class)
        ->and(fn () => folders()->rename($a, ' ', '1'))->toThrow(InvalidFolderName::class);
});

it('does not announce a rename to the same name', function (): void {
    $a = folders()->create(null, 'Alpha', '1');
    $events = captureMediaEvents();

    folders()->rename($a, 'Alpha', '1');

    expect($events)->toHaveCount(0);
});

it('caps the depth when creating', function (): void {
    config()->set('media-library.folders.max_depth', 2);
    $one = folders()->create(null, 'One', '1');
    $two = folders()->create($one, 'Two', '1');

    expect(fn () => folders()->create($two, 'Three', '1'))->toThrow(FolderTooDeep::class);
});

it('moves a folder and rewrites the path and depth of its whole subtree', function (): void {
    $a = folders()->create(null, 'A', '1');
    $b = folders()->create($a, 'B', '1');
    $c = folders()->create($b, 'C', '1');
    $x = folders()->create(null, 'X', '1', siteId: 3);
    $y = folders()->create($x, 'Y', '1');

    folders()->move($b, $y, '1');

    $b->refresh();
    $c->refresh();

    expect($b->parent_id)->toBe($y->id)
        ->and($b->path)->toBe($x->path.$y->id.'/'.$b->id.'/')
        ->and($b->depth)->toBe(3)
        ->and($c->path)->toBe($b->path.$c->id.'/')
        ->and($c->depth)->toBe(4)
        ->and($a->refresh()->path)->toBe('/'.$a->id.'/')
        ->and($c->breadcrumbs())->toHaveCount(4)
        ->and(array_map(static fn (MediaFolder $f): string => $f->name, $c->breadcrumbs()))->toBe(['X', 'Y', 'B', 'C']);
});

it('moves a nested folder back to the top level and clears nothing it should keep', function (): void {
    $top = folders()->create(null, 'Top', '1', siteId: 2);
    $inner = folders()->create($top, 'Inner', '1');
    $leaf = folders()->create($inner, 'Leaf', '1');

    folders()->move($inner, null, '1');

    expect($inner->refresh()->parent_id)->toBeNull()
        ->and($inner->path)->toBe('/'.$inner->id.'/')
        ->and($inner->depth)->toBe(1)
        ->and($leaf->refresh()->depth)->toBe(2)
        ->and($leaf->path)->toBe($inner->path.$leaf->id.'/')
        ->and($top->refresh()->site_id)->toBe(2);
});

it('clears the site tag when a tagged top-level folder gets a parent', function (): void {
    $other = folders()->create(null, 'Other', '1');
    $tagged = folders()->create(null, 'Tagged', '1', siteId: 5);

    folders()->move($tagged, $other, '1');

    expect($tagged->refresh()->site_id)->toBeNull();
});

it('treats a move to the current parent as nothing to do', function (): void {
    $a = folders()->create(null, 'A', '1');
    $b = folders()->create($a, 'B', '1');
    $events = captureMediaEvents();

    folders()->move($b, $a, '1');

    expect($events)->toHaveCount(0);
});

it('refuses to move a folder into itself or its own subtree', function (): void {
    $a = folders()->create(null, 'A', '1');
    $b = folders()->create($a, 'B', '1');
    $c = folders()->create($b, 'C', '1');

    expect(fn () => folders()->move($a, $a, '1'))->toThrow(FolderCycle::class)
        ->and(fn () => folders()->move($a, $c, '1'))->toThrow(FolderCycle::class)
        ->and($a->refresh()->parent_id)->toBeNull()
        ->and($c->refresh()->depth)->toBe(3);
});

it('refuses a move that would push any descendant past the depth cap', function (): void {
    config()->set('media-library.folders.max_depth', 3);
    $a = folders()->create(null, 'A', '1');
    $b = folders()->create($a, 'B', '1');
    $c = folders()->create($b, 'C', '1');
    $x = folders()->create(null, 'X', '1');

    // A -> B -> C is three deep; under X it would be four.
    expect(fn () => folders()->move($a, $x, '1'))->toThrow(FolderTooDeep::class)
        ->and($c->refresh()->depth)->toBe(3)
        ->and($a->refresh()->path)->toBe('/'.$a->id.'/');
});

it('refuses a move into a parent that already has that name', function (): void {
    $a = folders()->create(null, 'A', '1');
    folders()->create($a, 'Docs', '1');
    $other = folders()->create(null, 'Other', '1');
    $docs = folders()->create($other, 'docs', '1');

    expect(fn () => folders()->move($docs, $a, '1'))->toThrow(FolderNameTaken::class);
});

it('deletes an empty folder', function (): void {
    $a = folders()->create(null, 'A', '1');

    folders()->delete($a, '1');

    expect(MediaFolder::query()->count())->toBe(0);
});

it('refuses to delete a folder that has sub-folders or assets', function (): void {
    $a = folders()->create(null, 'A', '1');
    $b = folders()->create($a, 'B', '1');
    $asset = Images::upload();
    folders()->moveAssets([$asset], $b, '1');

    expect(fn () => folders()->delete($a, '1'))->toThrow(FolderNotEmpty::class)
        ->and(fn () => folders()->delete($b, '1'))->toThrow(FolderNotEmpty::class)
        ->and(MediaFolder::query()->count())->toBe(2)
        ->and($asset->refresh()->folder_id)->toBe($b->id);
});

it('deletes a folder after lifting its folders and assets into the parent', function (): void {
    $top = folders()->create(null, 'Top', '1');
    $doomed = folders()->create($top, 'Doomed', '1');
    $child = folders()->create($doomed, 'Child', '1');
    $grandchild = folders()->create($child, 'Grandchild', '1');
    $asset = Images::upload();
    folders()->moveAssets([$asset], $doomed, '1');

    folders()->deleteMovingContentsToParent($doomed, '1');

    expect(MediaFolder::query()->whereKey($doomed->id)->exists())->toBeFalse()
        ->and($asset->refresh()->folder_id)->toBe($top->id)
        ->and($child->refresh()->parent_id)->toBe($top->id)
        ->and($child->path)->toBe($top->path.$child->id.'/')
        ->and($child->depth)->toBe(2)
        ->and($grandchild->refresh()->depth)->toBe(3)
        ->and($grandchild->path)->toBe($child->path.$grandchild->id.'/');
});

it('lifts contents to the root when the deleted folder was top-level', function (): void {
    $doomed = folders()->create(null, 'Doomed', '1');
    $child = folders()->create($doomed, 'Child', '1');
    $asset = Images::upload();
    folders()->moveAssets([$asset], $doomed, '1');

    folders()->deleteMovingContentsToParent($doomed, '1');

    expect($asset->refresh()->folder_id)->toBeNull()
        ->and($child->refresh()->parent_id)->toBeNull()
        ->and($child->depth)->toBe(1);
});

it('lets a lifted folder share its name with the folder being deleted', function (): void {
    $doomed = folders()->create(null, 'Reports', '1');
    $inner = folders()->create($doomed, 'Reports', '1');

    folders()->deleteMovingContentsToParent($doomed, '1');

    expect($inner->refresh()->parent_id)->toBeNull();
});

it('undoes the whole lift when a lifted folder name is taken in the parent', function (): void {
    $top = folders()->create(null, 'Top', '1');
    folders()->create($top, 'Same', '1');
    $doomed = folders()->create($top, 'Doomed', '1');
    $clash = folders()->create($doomed, 'same', '1');
    $asset = Images::upload();
    folders()->moveAssets([$asset], $doomed, '1');

    expect(fn () => folders()->deleteMovingContentsToParent($doomed, '1'))->toThrow(FolderNameTaken::class)
        ->and(MediaFolder::query()->whereKey($doomed->id)->exists())->toBeTrue()
        ->and($clash->refresh()->parent_id)->toBe($doomed->id)
        ->and($asset->refresh()->folder_id)->toBe($doomed->id);
});

it('moves assets between folders and skips those already there', function (): void {
    $a = folders()->create(null, 'A', '1');
    $one = Images::upload('One');
    $two = Images::upload('Two');
    folders()->moveAssets([$one], $a, '1');

    $moved = folders()->moveAssets([$one, $two], $a, '1');

    expect($moved)->toBe(1)
        ->and($two->refresh()->folder_id)->toBe($a->id)
        ->and(folders()->moveAssets([$one, $two], null, '1'))->toBe(2)
        ->and($one->refresh()->folder_id)->toBeNull();
});

it('uploads into a folder and says so in the event', function (): void {
    $a = folders()->create(null, 'A', '1');
    $events = captureMediaEvents();

    $asset = app(MediaGovernanceService::class)->upload(Images::splitPng(), 'split.png', 'image/png', 'Alt', '1', null, null, $a);

    expect($asset->folder_id)->toBe($a->id)
        ->and($asset->folder->is($a))->toBeTrue()
        ->and($events[0]->payload()['folder_id'])->toBe($a->id);
});

it('records the folder an asset was in when it is deleted', function (): void {
    $a = folders()->create(null, 'A', '1');
    $asset = Images::upload();
    folders()->moveAssets([$asset], $a, '1');
    $events = captureMediaEvents();

    app(MediaGovernanceService::class)->delete($asset, '1');

    expect($events[0]->payload()['folder_id'])->toBe($a->id);
});

it('announces every folder change with ids only, never a name', function (): void {
    $events = captureMediaEvents();

    $secret = folders()->create(null, 'Jane Doe HR file', '7');
    $inner = folders()->create($secret, 'Disciplinary', '7');
    folders()->rename($secret, 'Jane Doe appeal', '7');
    folders()->move($inner, null, '7');
    $asset = Images::upload();
    folders()->moveAssets([$asset], $secret, '7');
    folders()->moveAssets([$asset], null, '7');
    folders()->delete($inner, '7');

    $names = array_map(static fn (MediaEvent $e): string => $e->name(), array_filter($events->getArrayCopy(), static fn (MediaEvent $e): bool => $e->name() !== 'media.asset.uploaded'));

    expect(array_values($names))->toBe([
        'media.folder.created', 'media.folder.created', 'media.folder.renamed', 'media.folder.moved',
        'media.asset.moved', 'media.asset.moved', 'media.folder.deleted',
    ]);

    $created = $events[0];
    $moved = $events[3];
    $assetMoved = $events[5];

    expect($created)->toBeInstanceOf(FolderCreated::class)->toBeInstanceOf(MediaFolderEvent::class)
        ->and($created->payload())->toBe(['folder_id' => $secret->id, 'uuid' => $secret->uuid, 'parent_id' => null, 'depth' => 1])
        ->and($created->subjectType())->toBe('media_folder')
        ->and($created->subjectId())->toBe((string) $secret->id)
        ->and($created->folderId())->toBe($secret->id)
        ->and($created->actorId())->toBe('7')
        ->and($moved->payload())->toBe(['folder_id' => $inner->id, 'from_parent_id' => $secret->id, 'to_parent_id' => null])
        ->and($assetMoved)->toBeInstanceOf(AssetMoved::class)
        ->and($assetMoved->payload())->toBe(['asset_id' => $asset->id, 'from_folder_id' => null, 'to_folder_id' => $secret->id])
        ->and($assetMoved->subjectType())->toBe('media_asset')
        ->and($assetMoved->assetId())->toBe($asset->id);

    $encoded = json_encode(array_map(static fn (MediaEvent $e): array => $e->payload(), $events->getArrayCopy()), JSON_THROW_ON_ERROR);

    expect($encoded)->not->toContain('Jane')->not->toContain('Disciplinary')->not->toContain('HR file');
});

it('rolls a folder change back when a listener refuses it', function (): void {
    Event::listen(FolderCreated::class, static function (): void {
        throw new RuntimeException('audit store unavailable');
    });

    expect(fn () => folders()->create(null, 'A', '1'))->toThrow(RuntimeException::class)
        ->and(MediaFolder::query()->count())->toBe(0);
});

it('scopes assets to a folder, optionally with everything below it', function (): void {
    $a = folders()->create(null, 'A', '1');
    $b = folders()->create($a, 'B', '1');
    $sibling = folders()->create(null, 'AB', '1');
    $root = Images::upload('root');
    $inA = Images::upload('in a');
    $inB = Images::upload('in b');
    $inSibling = Images::upload('in sibling');
    folders()->moveAssets([$inA], $a, '1');
    folders()->moveAssets([$inB], $b, '1');
    folders()->moveAssets([$inSibling], $sibling, '1');

    $ids = static fn ($query): array => $query->orderBy('id')->pluck('id')->all();

    expect($ids(MediaAsset::query()->inFolder(null)))->toBe([$root->id])
        ->and($ids(MediaAsset::query()->inFolder(null, true)))->toBe([$root->id, $inA->id, $inB->id, $inSibling->id])
        ->and($ids(MediaAsset::query()->inFolder($a)))->toBe([$inA->id])
        ->and($ids(MediaAsset::query()->inFolder($a, true)))->toBe([$inA->id, $inB->id])
        ->and($ids(MediaAsset::query()->inFolder($b, true)))->toBe([$inB->id]);
});

it('matches a subtree by prefix with LIKE wildcards escaped', function (): void {
    $a = folders()->create(null, 'A', '1');
    $b = folders()->create($a, 'B', '1');

    // A path never holds % or _, but the escaping must not break an ordinary one.
    expect($a->descendantsQuery()->pluck('id')->all())->toBe([$b->id])
        ->and($a->subtreeQuery()->count())->toBe(2)
        ->and($a->contains($b))->toBeTrue()
        ->and($b->contains($a))->toBeFalse()
        ->and($b->ancestorIds())->toBe([$a->id])
        ->and($a->ancestors())->toBe([])
        ->and($b->ancestors()[0]->is($a))->toBeTrue();
});

it('labels folders by their path with a single query', function (): void {
    $a = folders()->create(null, 'Reports', '1');
    $b = folders()->create($a, '2025', '1');
    $c = folders()->create($b, 'Q1', '1');

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $labels = MediaFolder::pathLabels([$a, $b, $c]);

    expect($labels)->toBe([$a->id => 'Reports', $b->id => 'Reports / 2025', $c->id => 'Reports / 2025 / Q1'])
        ->and($queries)->toBe(1)
        ->and(MediaFolder::pathLabels([]))->toBe([]);
});

it('never serialises a password hash and reads members through a relation', function (): void {
    $a = folders()->create(null, 'A', '1');
    $a->forceFill(['password_hash' => 'secret-hash'])->save();
    DB::table('lib_folder_members')->insert(['media_folder_id' => $a->id, 'user_id' => 5, 'role' => 'manage']);

    expect($a->refresh()->toArray())->not->toHaveKey('password_hash')
        ->and($a->members)->toHaveCount(1)
        ->and($a->members[0]->role)->toBe('manage')
        ->and(MediaFolder::findByUuid($a->uuid)?->is($a))->toBeTrue()
        ->and(MediaFolder::findByUuid('nope'))->toBeNull();
});
