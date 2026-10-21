<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Events;

use Banyan\FilamentMediaLibrary\Models\MediaAsset;
use Banyan\FilamentMediaLibrary\Models\MediaVersion;

/** A new file became the asset's current version; the old one is kept. */
final readonly class AssetReplaced implements MediaEvent
{
    public function __construct(
        public MediaAsset $asset,
        public MediaVersion $version,
        public ?int $supersededVersionId,
        public string $actor,
    ) {}

    public function name(): string
    {
        return 'media.asset.replaced';
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
            'version_id' => $this->version->id,
            'superseded_version_id' => $this->supersededVersionId,
            'size_bytes' => $this->version->size_bytes,
        ];
    }
}
