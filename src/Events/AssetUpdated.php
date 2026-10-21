<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Events;

use Banyan\FilamentMediaLibrary\Models\MediaAsset;
use DateTimeInterface;

/** Alt text or licence expiry was edited. Whether the alt text changed is recorded, never its value. */
final readonly class AssetUpdated implements MediaEvent
{
    public function __construct(
        public MediaAsset $asset,
        public bool $altTextChanged,
        public ?DateTimeInterface $licenseExpiresAt,
        public string $actor,
    ) {}

    public function name(): string
    {
        return 'media.asset.updated';
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
            'alt_text_changed' => $this->altTextChanged,
            'license_expires_at' => $this->licenseExpiresAt?->format(DATE_ATOM),
        ];
    }
}
