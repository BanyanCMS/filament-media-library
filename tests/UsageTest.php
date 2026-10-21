<?php

declare(strict_types=1);

use Banyan\FilamentMediaLibrary\Contracts\UsageResolver;
use Banyan\FilamentMediaLibrary\Exceptions\AssetReferenced;
use Banyan\FilamentMediaLibrary\Governance\MediaExpiryReport;
use Banyan\FilamentMediaLibrary\Governance\MediaGovernanceService;
use Banyan\FilamentMediaLibrary\Tests\Support\Images;
use Banyan\FilamentMediaLibrary\Tests\Support\User;
use Banyan\FilamentMediaLibrary\Usage\AssetUsage;
use Banyan\FilamentMediaLibrary\Usage\DatabaseUsageResolver;
use Banyan\FilamentMediaLibrary\Usage\UsageRecorder;
use Illuminate\Support\Facades\DB;

covers(UsageRecorder::class, DatabaseUsageResolver::class, MediaExpiryReport::class);

it('binds the database resolver by default', function (): void {
    expect(app(UsageResolver::class))->toBeInstanceOf(DatabaseUsageResolver::class);
});

it('records, replaces and forgets the assets a model uses', function (): void {
    $owner = User::make();
    $hero = Images::upload();
    $logo = Images::upload();
    $recorder = app(UsageRecorder::class);
    $usages = app(UsageResolver::class);

    $recorder->sync($owner, 'avatar', [$hero->id, $hero->id, $logo->id]);
    $recorder->sync($owner, 'banner', [$hero->id], 'en');

    expect($usages->count($hero->id))->toBe(2)
        ->and($usages->counts([$hero->id, $logo->id]))->toBe([$hero->id => 2, $logo->id => 1])
        ->and($usages->counts([]))->toBe([])
        ->and($usages->usages($hero->id))->toEqual([
            new AssetUsage('User #'.$owner->id, 'User', 'avatar'),
            new AssetUsage('User #'.$owner->id, 'User', 'banner', 'en'),
        ]);

    $recorder->sync($owner, 'avatar', [$logo->id]);

    expect($usages->count($hero->id))->toBe(1);

    $recorder->forget($owner);

    expect(DB::table('lib_usages')->count())->toBe(0);
});

it('refuses to delete an asset that is still used', function (): void {
    $asset = Images::upload();
    app(UsageRecorder::class)->sync(User::make(), 'avatar', [$asset->id]);

    expect(fn () => app(MediaGovernanceService::class)->delete($asset, '1'))->toThrow(AssetReferenced::class)
        ->and(DB::table('lib_assets')->count())->toBe(1);
});

it('reports lapsed licences and how many of them are still used', function (): void {
    $used = Images::upload(expires: '-2 days');
    Images::upload(expires: '-1 day');
    Images::upload(expires: '+5 days');
    Images::upload(expires: '+90 days');
    app(UsageRecorder::class)->sync(User::make(), 'avatar', [$used->id]);

    $summary = app(MediaExpiryReport::class)->summary(warningDays: 30);

    expect($summary->expired)->toBe(2)
        ->and($summary->expiredReferenced)->toBe(1)
        ->and($summary->expiringSoon)->toBe(1)
        ->and($summary->expiringSoonReferenced)->toBe(0)
        ->and($summary->warningDays)->toBe(30)
        ->and($summary->hasIssues())->toBeTrue();
});

it('refuses a model that has not been saved', function (): void {
    expect(fn () => app(UsageRecorder::class)->sync(new User, 'avatar', [1]))->toThrow(InvalidArgumentException::class);
});
