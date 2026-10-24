<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Events;

use Banyan\FilamentMediaLibrary\Models\MediaAsset;

/** A video's captions file was attached, replaced or removed. References only. */
final readonly class CaptionsChanged extends AssetEvent
{
    public const string NAME = 'media.asset.captions_changed';

    public function __construct(
        public MediaAsset $asset,
        public ?int $captionsAssetId,
        string $actor,
    ) {
        parent::__construct($asset->id, $actor);
    }

    public function payload(): array
    {
        return [
            ...$this->reference($this->asset),
            'captions_asset_id' => $this->captionsAssetId,
        ];
    }
}
