<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Events;

use Banyan\FilamentMediaLibrary\Models\MediaAsset;

/** A new asset and its first version were stored. */
final readonly class AssetUploaded implements MediaEvent
{
    public function __construct(
        public MediaAsset $asset,
        public string $actor,
    ) {}

    public function name(): string
    {
        return 'media.asset.uploaded';
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
            'mime_type' => $this->asset->mime_type,
            'size_bytes' => $this->asset->size_bytes,
            'width' => $this->asset->width,
            'height' => $this->asset->height,
        ];
    }
}
