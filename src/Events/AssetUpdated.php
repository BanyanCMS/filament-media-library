<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Events;

use Banyan\FilamentMediaLibrary\Models\MediaAsset;
use DateTimeInterface;

/** Alt text, title or licence expiry was edited. Whether the text changed is recorded, never its value. */
final readonly class AssetUpdated implements MediaAssetEvent
{
    use AssetSubject;

    public function __construct(
        public MediaAsset $asset,
        public bool $altTextChanged,
        public ?DateTimeInterface $licenseExpiresAt,
        public string $actor,
        public bool $titleChanged = false,
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
            'kind' => $this->asset->kind->value,
            'folder_id' => $this->asset->folder_id,
            'alt_text_changed' => $this->altTextChanged,
            'title_changed' => $this->titleChanged,
            'license_expires_at' => $this->licenseExpiresAt?->format(DATE_ATOM),
        ];
    }
}
