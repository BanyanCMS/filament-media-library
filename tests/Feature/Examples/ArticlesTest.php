<?php

declare(strict_types=1);

use Banyan\FilamentMediaLibrary\Access\MediaLibraryGate;
use Banyan\FilamentMediaLibrary\Contracts\UsageResolver;
use Banyan\FilamentMediaLibrary\Examples\Articles\Access\MediaPermissions;
use Banyan\FilamentMediaLibrary\Examples\Articles\Filament\Resources\Articles\Pages\CreateArticle;
use Banyan\FilamentMediaLibrary\Examples\Articles\Filament\Resources\Articles\Pages\EditArticle;
use Banyan\FilamentMediaLibrary\Examples\Articles\Filament\Resources\Articles\Pages\ListArticles;
use Banyan\FilamentMediaLibrary\Examples\Articles\Filament\Resources\MediaAudit\MediaAuditResource;
use Banyan\FilamentMediaLibrary\Examples\Articles\Filament\Resources\MediaAudit\Pages\ListMediaAudit;
use Banyan\FilamentMediaLibrary\Examples\Articles\Models\Article;
use Banyan\FilamentMediaLibrary\Examples\Articles\Models\MediaAuditEntry;
use Banyan\FilamentMediaLibrary\Exceptions\AssetReferenced;
use Banyan\FilamentMediaLibrary\Governance\MediaGovernanceService;
use Banyan\FilamentMediaLibrary\Tests\Support\Images;
use Banyan\FilamentMediaLibrary\Tests\Support\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

covers(Article::class, MediaPermissions::class);

function articleWith(?int $hero, array $gallery = []): Article
{
    return Article::query()->create(['title' => 'Open day', 'body' => 'Come along.', 'hero_id' => $hero, 'gallery' => $gallery]);
}

/** @return list<array{string, string}> field and asset id pairs, ordered */
function articleUsages(Article $article): array
{
    return DB::table('lib_usages')->where('usable_id', (string) $article->id)->orderBy('field')->orderBy('media_asset_id')
        ->get()->map(fn ($row): array => [$row->field, (string) $row->media_asset_id])->all();
}

it('records usage when an article is saved, changes it on update and drops it on delete', function (): void {
    [$a, $b, $c] = [Images::upload(), Images::upload(), Images::upload()];

    $article = articleWith($a->id, [$b->id, $c->id]);

    expect(articleUsages($article))->toBe([['gallery', (string) $b->id], ['gallery', (string) $c->id], ['hero_id', (string) $a->id]])
        ->and(app(UsageResolver::class)->count($a->id))->toBe(1);

    $article->update(['hero_id' => $c->id, 'gallery' => [$b->id]]);

    expect(articleUsages($article))->toBe([['gallery', (string) $b->id], ['hero_id', (string) $c->id]])
        ->and(app(UsageResolver::class)->count($a->id))->toBe(0);

    $article->update(['hero_id' => null, 'gallery' => null]);

    expect(articleUsages($article))->toBe([]);

    $article->update(['hero_id' => $a->id]);
    $article->delete();

    expect(DB::table('lib_usages')->count())->toBe(0);
});

it('lists the article on the asset usage table', function (): void {
    $asset = Images::upload();
    $article = articleWith($asset->id);

    $usages = app(UsageResolver::class)->usages($asset->id);

    expect($usages)->toHaveCount(1)
        ->and($usages[0]->label)->toBe('Article #'.$article->id)
        ->and($usages[0]->field)->toBe('hero_id');
});

it('refuses to delete an asset an article uses until the article lets go', function (): void {
    $asset = Images::upload();
    $article = articleWith(null, [$asset->id]);

    expect(fn () => app(MediaGovernanceService::class)->delete($asset, '1'))->toThrow(AssetReferenced::class);

    $article->delete();
    app(MediaGovernanceService::class)->delete($asset, '1');

    expect(DB::table('lib_assets')->count())->toBe(0);
});

it('writes one audit row per library change, with references only', function (): void {
    $service = app(MediaGovernanceService::class);
    $asset = Images::upload('Mia Chan at the open day', '7');
    $service->setFocalPoint($asset, 0.25, 0.75, '7');
    $service->syncTags($asset, ['open-day'], '7');
    $service->delete($asset, '7');

    $rows = MediaAuditEntry::query()->orderBy('id')->get();

    expect($rows->pluck('event')->all())->toBe(['media.asset.uploaded', 'media.asset.refocused', 'media.asset.tagged', 'media.asset.deleted'])
        ->and($rows->pluck('actor_id')->unique()->all())->toBe(['7'])
        ->and($rows->pluck('asset_id')->unique()->all())->toBe([$asset->id])
        ->and($rows[0]->payload['mime_type'])->toBe('image/png');

    $stored = (string) json_encode(DB::table('example_media_audit')->get());

    expect($stored)->not->toContain('Mia Chan')->not->toContain('split.png');
});

it('rolls the change back when the audit listener fails', function (): void {
    DB::statement('drop table example_media_audit');

    expect(fn () => Images::upload())->toThrow(QueryException::class)
        ->and(DB::table('lib_assets')->count())->toBe(0);
});

it('maps media.manage onto the configured editors', function (): void {
    config(['example-articles.editors' => ['Editor@example.test']]);
    MediaPermissions::define();

    $editor = User::make('Editor');
    $reader = User::make('Reader');

    expect(Gate::forUser($editor)->allows('media.manage'))->toBeTrue()
        ->and(Gate::forUser($reader)->allows('media.manage'))->toBeFalse()
        ->and(MediaPermissions::isEditor(null))->toBeFalse();

    $this->actingAs($editor);
    expect(MediaLibraryGate::allowsManage())->toBeTrue();

    $this->actingAs($reader);
    expect(MediaLibraryGate::allowsManage())->toBeFalse()->and(MediaLibraryGate::allowsBrowse())->toBeTrue();
});

it('treats nobody as an editor when the list is missing or malformed', function (): void {
    config(['example-articles.editors' => 'not-a-list']);

    expect(MediaPermissions::editors())->toBe([])
        ->and(MediaPermissions::isEditor(User::make()))->toBeFalse();
});

it('creates and edits an article through the resource, syncing usage', function (): void {
    [$a, $b] = [Images::upload(), Images::upload()];
    $user = User::make();

    Livewire::actingAs($user)->test(CreateArticle::class)
        ->fillForm(['title' => 'Hello', 'body' => 'World', 'hero_id' => $a->id, 'gallery' => [$b->id]])
        ->call('create')
        ->assertHasNoFormErrors();

    $article = Article::query()->firstOrFail();

    expect($article->author_id)->toBe($user->id)
        ->and(articleUsages($article))->toBe([['gallery', (string) $b->id], ['hero_id', (string) $a->id]]);

    Livewire::actingAs($user)->test(EditArticle::class, ['record' => $article->id])
        ->fillForm(['hero_id' => $b->id, 'gallery' => []])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(articleUsages($article))->toBe([['hero_id', (string) $b->id]]);

    Livewire::actingAs($user)->test(ListArticles::class)->assertCanSeeTableRecords([$article]);
});

it('shows the audit log read-only', function (): void {
    $asset = Images::upload(actor: '3');

    Livewire::actingAs(User::make())->test(ListMediaAudit::class)
        ->assertCanSeeTableRecords(MediaAuditEntry::query()->where('asset_id', $asset->id)->get())
        ->assertSee('media.asset.uploaded');

    expect(MediaAuditResource::canCreate())->toBeFalse();
});

it('renders the public article with a signed hero, srcset and the alt text', function (): void {
    $hero = Images::upload('Students at the open day');
    $extra = Images::upload('Library stairs');
    $article = articleWith($hero->id, [$extra->id]);

    $html = $this->get('/articles/'.$article->id)->assertOk()->getContent();

    expect($html)->toContain('alt="Students at the open day"')
        ->toContain('alt="Library stairs"')
        ->toContain('srcset=')
        ->toContain('signature=')
        ->toContain('600w')
        ->toContain('1200w');

    preg_match('/<img class="hero" src="([^"]+)"/', $html, $m);
    $src = html_entity_decode($m[1] ?? '');

    $response = $this->get($src)->assertOk();
    expect($response->headers->get('content-type'))->toContain('image/png');
});

it('renders an article without a hero and returns 404 for a missing one', function (): void {
    $article = articleWith(null);

    $this->get('/articles/'.$article->id)->assertOk()->assertDontSee('class="hero"', false);
    $this->get('/articles/999')->assertNotFound();
});
