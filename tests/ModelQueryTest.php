<?php

declare(strict_types=1);

use Banyan\FilamentMediaLibrary\Governance\MediaGovernanceService;
use Banyan\FilamentMediaLibrary\Models\MediaAsset;
use Banyan\FilamentMediaLibrary\Tests\Support\Images;
use Banyan\FilamentMediaLibrary\Tests\Support\User;
use Banyan\FilamentMediaLibrary\Usage\UsageRecorder;

covers(MediaAsset::class);

it('searches filename and alt text, matching wildcards literally', function (): void {
    $campus = Images::upload('The campus at dusk');
    Images::upload('A 100% red square');

    expect(MediaAsset::query()->search('campus')->pluck('id')->all())->toBe([$campus->id])
        ->and(MediaAsset::query()->search('100%')->count())->toBe(1)
        ->and(MediaAsset::query()->search('%')->count())->toBe(1)
        ->and(MediaAsset::query()->search('  ')->count())->toBe(2);
});

it('filters by tag name, case-insensitively', function (): void {
    $tagged = Images::upload();
    Images::upload();
    app(MediaGovernanceService::class)->syncTags($tagged, ['Campus', 'events'], '1');

    expect(MediaAsset::query()->tagged('CAMPUS')->pluck('id')->all())->toBe([$tagged->id])
        ->and(MediaAsset::query()->tagged(['nope', 'events'])->count())->toBe(1);
});

it('selects expired and soon-expiring licences', function (): void {
    $expired = Images::upload(expires: '-1 day');
    $soon = Images::upload(expires: '+5 days');
    Images::upload(expires: '+90 days');
    Images::upload();

    expect(MediaAsset::query()->expired()->pluck('id')->all())->toBe([$expired->id])
        ->and(MediaAsset::query()->expiringWithin(30)->pluck('id')->all())->toBe([$soon->id])
        ->and(MediaAsset::query()->selectable()->count())->toBe(3);
});

it('selects assets in use, as the usage resolver counts them', function (): void {
    $used = Images::upload();
    Images::upload();
    app(UsageRecorder::class)->sync(User::make(), 'avatar', [$used->id]);

    expect(MediaAsset::query()->used()->pluck('id')->all())->toBe([$used->id]);
});

it('finds by uuid and builds a signed rendition URL', function (): void {
    $asset = Images::upload();

    expect(MediaAsset::findByUuid($asset->uuid)?->id)->toBe($asset->id)
        ->and(MediaAsset::findByUuid('00000000-0000-4000-8000-000000000000'))->toBeNull()
        ->and($asset->url(1200, 630, 'crop'))->toContain('/media/'.$asset->uuid.'/render')->toContain('w=1200')->toContain('signature=');
});
