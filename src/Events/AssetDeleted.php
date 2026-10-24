<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Events;

/**
 * An asset, its versions and its renditions were removed.
 *
 * An asset that is in use cannot be deleted, so `usageCount` is always 0. The
 * property and the `usage_count` payload key stay so existing listeners keep working.
 */
final readonly class AssetDeleted implements MediaAssetEvent
{
    use AssetSubject;

    public function __construct(
        public int $id,
        public string $uuid,
        public int $usageCount,
        public string $actor,
        public string $kind = 'image',
        public ?int $folderId = null,
    ) {}

    public function name(): string
    {
        return 'media.asset.deleted';
    }

    public function assetId(): int
    {
        return $this->id;
    }

    public function actorId(): string
    {
        return $this->actor;
    }

    public function payload(): array
    {
        return [
            'asset_id' => $this->id,
            'uuid' => $this->uuid,
            'kind' => $this->kind,
            'folder_id' => $this->folderId,
            'usage_count' => $this->usageCount,
        ];
    }
}
