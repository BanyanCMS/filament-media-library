<?php

declare(strict_types=1);

use Banyan\FilamentMediaLibrary\Enums\MediaKind;
use Banyan\FilamentMediaLibrary\Events\AssetDeleted;
use Banyan\FilamentMediaLibrary\Events\AssetEvent;
use Banyan\FilamentMediaLibrary\Events\AssetMoved;
use Banyan\FilamentMediaLibrary\Events\AssetReplaced;
use Banyan\FilamentMediaLibrary\Events\AssetTagged;
use Banyan\FilamentMediaLibrary\Events\AssetUpdated;
use Banyan\FilamentMediaLibrary\Events\AssetUploaded;
use Banyan\FilamentMediaLibrary\Events\CaptionsChanged;
use Banyan\FilamentMediaLibrary\Events\FocalPointChanged;
use Banyan\FilamentMediaLibrary\Events\FolderAccessChanged;
use Banyan\FilamentMediaLibrary\Events\FolderCreated;
use Banyan\FilamentMediaLibrary\Events\FolderDeleted;
use Banyan\FilamentMediaLibrary\Events\FolderEvent;
use Banyan\FilamentMediaLibrary\Events\FolderMembersChanged;
use Banyan\FilamentMediaLibrary\Events\FolderMoved;
use Banyan\FilamentMediaLibrary\Events\FolderRenamed;
use Banyan\FilamentMediaLibrary\Events\FolderUnlockFailed;
use Banyan\FilamentMediaLibrary\Events\MediaAssetEvent;
use Banyan\FilamentMediaLibrary\Events\MediaFolderEvent;
use Banyan\FilamentMediaLibrary\Events\TranscriptChanged;
use Banyan\FilamentMediaLibrary\Models\MediaVersion;
use Banyan\FilamentMediaLibrary\Tests\Support\Images;

covers(
    AssetEvent::class, FolderEvent::class, AssetUploaded::class, AssetReplaced::class, AssetUpdated::class,
    CaptionsChanged::class, FocalPointChanged::class, TranscriptChanged::class, AssetTagged::class,
    AssetMoved::class, AssetDeleted::class, FolderCreated::class, FolderRenamed::class, FolderMoved::class,
    FolderDeleted::class, FolderAccessChanged::class, FolderMembersChanged::class, FolderUnlockFailed::class,
);

/**
 * Every event, built with fixed values, with the name and payload a listener
 * (the audit chain, the webhooks) has relied on since 0.1.
 *
 * @return list<array{0: string, 1: array<string, scalar|null>}>
 */
function mediaEventShapes(): array
{
    $asset = Images::upload();
    $version = MediaVersion::query()->findOrFail($asset->current_version_id);
    $reference = ['asset_id' => $asset->id, 'uuid' => $asset->uuid, 'kind' => 'image', 'folder_id' => null];

    return [
        [new AssetUploaded($asset, '9'), 'media.asset.uploaded', 'media_asset', (string) $asset->id, $reference + ['mime_type' => 'image/png', 'size_bytes' => $asset->size_bytes, 'width' => 400, 'height' => 200]],
        [new AssetReplaced($asset, $version, 4, '9'), 'media.asset.replaced', 'media_asset', (string) $asset->id, $reference + ['version_id' => $version->id, 'superseded_version_id' => 4, 'size_bytes' => $version->size_bytes]],
        [new AssetUpdated($asset, true, new DateTimeImmutable('2030-01-01T00:00:00+00:00'), '9', true), 'media.asset.updated', 'media_asset', (string) $asset->id, $reference + ['alt_text_changed' => true, 'title_changed' => true, 'license_expires_at' => '2030-01-01T00:00:00+00:00']],
        [new CaptionsChanged($asset, 12, '9'), 'media.asset.captions_changed', 'media_asset', (string) $asset->id, $reference + ['captions_asset_id' => 12]],
        [new FocalPointChanged($asset, '9'), 'media.asset.refocused', 'media_asset', (string) $asset->id, $reference + ['focal_x' => $asset->focal_x, 'focal_y' => $asset->focal_y]],
        [new TranscriptChanged($asset, true, '9'), 'media.asset.transcript_changed', 'media_asset', (string) $asset->id, $reference + ['has_transcript' => true]],
        [new AssetTagged($asset, 3, '9'), 'media.asset.tagged', 'media_asset', (string) $asset->id, $reference + ['tag_count' => 3]],
        [new AssetMoved(5, null, 8, '9'), 'media.asset.moved', 'media_asset', '5', ['asset_id' => 5, 'from_folder_id' => null, 'to_folder_id' => 8]],
        [new AssetDeleted(5, 'abc', 0, '9', MediaKind::Document, 2), 'media.asset.deleted', 'media_asset', '5', ['asset_id' => 5, 'uuid' => 'abc', 'kind' => 'document', 'folder_id' => 2, 'usage_count' => 0]],
        [new FolderCreated(7, 'f-uuid', null, 1, '9'), 'media.folder.created', 'media_folder', '7', ['folder_id' => 7, 'uuid' => 'f-uuid', 'parent_id' => null, 'depth' => 1]],
        [new FolderRenamed(7, 'f-uuid', '9'), 'media.folder.renamed', 'media_folder', '7', ['folder_id' => 7, 'uuid' => 'f-uuid']],
        [new FolderMoved(7, 1, null, '9'), 'media.folder.moved', 'media_folder', '7', ['folder_id' => 7, 'from_parent_id' => 1, 'to_parent_id' => null]],
        [new FolderDeleted(7, 'f-uuid', '9'), 'media.folder.deleted', 'media_folder', '7', ['folder_id' => 7, 'uuid' => 'f-uuid']],
        [new FolderAccessChanged(7, 'shared', 'members', 2, '9'), 'media.folder.access_changed', 'media_folder', '7', ['folder_id' => 7, 'from' => 'shared', 'to' => 'members', 'members_count' => 2]],
        [new FolderMembersChanged(7, 33, 'added', 'view', 2, '9'), 'media.folder.members_changed', 'media_folder', '7', ['folder_id' => 7, 'user_id' => 33, 'change' => 'added', 'role' => 'view', 'members_count' => 2]],
        [new FolderUnlockFailed(7, 3, '9'), 'media.folder.unlock_failed', 'media_folder', '7', ['folder_id' => 7, 'attempts' => 3]],
    ];
}

it('keeps the name, subject and payload of every event', function (): void {
    foreach (mediaEventShapes() as [$event, $name, $subjectType, $subjectId, $payload]) {
        expect($event->name())->toBe($name)
            ->and($event::NAME)->toBe($name)
            ->and($event->subjectType())->toBe($subjectType)
            ->and($event->subjectId())->toBe($subjectId)
            ->and($event->actorId())->toBe('9')
            ->and($event->actor)->toBe('9')
            ->and($event->payload())->toBe($payload);
    }
});

it('gives every event one unique name and one of the two base classes', function (): void {
    $events = array_column(mediaEventShapes(), 0);

    expect(array_unique(array_map(static fn (object $event): string => $event::NAME, $events)))->toHaveCount(count($events));

    foreach ($events as $event) {
        expect($event)->toBeInstanceOf($event instanceof MediaAssetEvent ? AssetEvent::class : FolderEvent::class);
        expect($event instanceof MediaAssetEvent || $event instanceof MediaFolderEvent)->toBeTrue();
    }
});

it('exposes the id of the subject under one property name', function (): void {
    foreach (mediaEventShapes() as [$event]) {
        $event instanceof AssetEvent
            ? expect($event->assetId)->toBe($event->assetId())
            : expect($event->folderId)->toBe($event->folderId());
    }
});

it('reports the kind of a deleted asset as the enum and its value in the payload', function (): void {
    $event = new AssetDeleted(1, 'u', 0, '9');

    expect($event->kind)->toBe(MediaKind::Image)
        ->and($event->payload()['kind'])->toBe('image');
});
