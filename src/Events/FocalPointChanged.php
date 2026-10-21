<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Events;

use Banyan\FilamentMediaLibrary\Models\MediaAsset;

/** The point every crop keeps was moved. */
final readonly class FocalPointChanged implements MediaEvent
{
    public function __construct(
        public MediaAsset $asset,
        public string $actor,
    ) {}

    public function name(): string
    {
        return 'media.asset.refocused';
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
            'focal_x' => $this->asset->focal_x,
            'focal_y' => $this->asset->focal_y,
        ];
    }
}
