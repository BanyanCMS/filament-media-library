<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Events;

use Banyan\FilamentMediaLibrary\Models\MediaAsset;

/** The asset's tags were replaced. */
final readonly class AssetTagged implements MediaAssetEvent
{
    use AssetSubject;

    public function __construct(
        public MediaAsset $asset,
        public int $tagCount,
        public string $actor,
    ) {}

    public function name(): string
    {
        return 'media.asset.tagged';
    }

    public function assetId(): int
    {
        return $this->asset->id;
    }

    public function actorId(): string
    {
        return $this->actor;
    }

    public function payload(): array
    {
        return [
            'asset_id' => $this->asset->id,
            'uuid' => $this->asset->uuid,
            'kind' => $this->asset->kind->value,
            'folder_id' => $this->asset->folder_id,
            'tag_count' => $this->tagCount,
        ];
    }
}
