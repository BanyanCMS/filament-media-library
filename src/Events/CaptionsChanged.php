<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Events;

use Banyan\FilamentMediaLibrary\Models\MediaAsset;

/** A video's captions file was attached, replaced or removed. References only. */
final readonly class CaptionsChanged implements MediaAssetEvent
{
    use AssetSubject;

    public function __construct(
        public MediaAsset $asset,
        public ?int $captionsAssetId,
        public string $actor,
    ) {}

    public function name(): string
    {
        return 'media.asset.captions_changed';
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
            'captions_asset_id' => $this->captionsAssetId,
        ];
    }
}
