<?php

declare(strict_types=1);

use Banyan\FilamentMediaLibrary\Events\AssetDeleted;
use Banyan\FilamentMediaLibrary\Events\AssetReplaced;
use Banyan\FilamentMediaLibrary\Events\AssetTagged;
use Banyan\FilamentMediaLibrary\Events\AssetUpdated;
use Banyan\FilamentMediaLibrary\Events\AssetUploaded;
use Banyan\FilamentMediaLibrary\Events\FocalPointChanged;
use Banyan\FilamentMediaLibrary\Events\MediaEvent;
use Banyan\FilamentMediaLibrary\Exceptions\AltTextRequired;
use Banyan\FilamentMediaLibrary\Governance\MediaGovernanceService;
use Banyan\FilamentMediaLibrary\Models\MediaAsset;
use Banyan\FilamentMediaLibrary\Tests\Support\Images;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

covers(MediaGovernanceService::class, AssetUploaded::class, AssetReplaced::class, AssetUpdated::class, FocalPointChanged::class, AssetTagged::class, AssetDeleted::class);

it('stores an upload in the configured tables and announces it', function (): void {
    $events = [];
    Event::listen(MediaEvent::class, function (MediaEvent $event) use (&$events): void {
        $events[] = $event;
    });

    $asset = Images::upload(actor: '7');

    expect(DB::table('lib_assets')->count())->toBe(1)
        ->and(DB::table('lib_versions')->count())->toBe(1)
        ->and($asset->current_version_id)->not->toBeNull()
        ->and($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(AssetUploaded::class)
        ->and($events[0]->name())->toBe('media.asset.uploaded')
        ->and($events[0]->actorId())->toBe('7')
        ->and($events[0]->assetId())->toBe($asset->id)
        ->and($events[0]->payload())->toBe([
            'asset_id' => $asset->id,
            'uuid' => $asset->uuid,
            'mime_type' => 'image/png',
            'size_bytes' => $asset->size_bytes,
            'width' => 400,
            'height' => 200,
        ]);
});

it('names every change with a stable event and never puts alt text or a filename in a payload', function (): void {
    $events = [];
    Event::listen(MediaEvent::class, function (MediaEvent $event) use (&$events): void {
        $events[] = $event;
    });
    $service = app(MediaGovernanceService::class);

    $asset = Images::upload('Jane Doe at the podium');
    $service->replace($asset, Images::splitPng(), 'cv-jane-doe.png', 'image/png', '1');
    $service->updateDetails($asset->refresh(), 'Jane Doe, smiling', new DateTimeImmutable('2030-01-01T00:00:00+00:00'), '1');
    $service->setFocalPoint($asset, 0.25, 0.75, '1');
    $service->syncTags($asset, ['Campus', 'events'], '1');
    $service->delete($asset, '1');

    expect(array_map(static fn (MediaEvent $e): string => $e->name(), $events))->toBe([
        'media.asset.uploaded',
        'media.asset.replaced',
        'media.asset.updated',
        'media.asset.refocused',
        'media.asset.tagged',
        'media.asset.deleted',
    ]);

    $payloads = json_encode(array_map(static fn (MediaEvent $e): array => $e->payload(), $events), JSON_THROW_ON_ERROR);

    expect($payloads)->not->toContain('Jane')->not->toContain('jane')->not->toContain('split.png')
        ->and($events[2]->payload())->toMatchArray(['alt_text_changed' => true, 'license_expires_at' => '2030-01-01T00:00:00+00:00'])
        ->and($events[3]->payload())->toMatchArray(['focal_x' => 0.25, 'focal_y' => 0.75])
        ->and($events[4]->payload())->toMatchArray(['tag_count' => 2])
        ->and($events[5]->payload())->toMatchArray(['asset_id' => $asset->id, 'usage_count' => 0]);

    /** @var AssetReplaced $replaced */
    $replaced = $events[1];
    expect($replaced->payload()['superseded_version_id'])->not->toBe($replaced->payload()['version_id'])
        ->and(DB::table('lib_assets')->count())->toBe(0);
});

it('rolls the write back when a listener refuses it', function (): void {
    Event::listen(AssetUploaded::class, static function (): void {
        throw new RuntimeException('audit store unavailable');
    });

    expect(fn () => Images::upload())->toThrow(RuntimeException::class, 'audit store unavailable')
        ->and(MediaAsset::query()->count())->toBe(0)
        ->and(DB::table('lib_versions')->count())->toBe(0);
});

it('refuses an asset without alt text before anything is written or announced', function (): void {
    Event::fake([MediaEvent::class]);

    expect(fn () => Images::upload('   '))->toThrow(AltTextRequired::class)
        ->and(MediaAsset::query()->count())->toBe(0);

    Event::assertNotDispatched(AssetUploaded::class);
});
