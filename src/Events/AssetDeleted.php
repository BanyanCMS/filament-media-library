<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Events;

/** An asset, its versions and its renditions were removed. The usage count at that moment is kept, because no later query can reconstruct it. */
final readonly class AssetDeleted implements MediaEvent
{
    public function __construct(
        public int $id,
        public string $uuid,
        public int $usageCount,
        public string $actor,
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
            'usage_count' => $this->usageCount,
        ];
    }
}
