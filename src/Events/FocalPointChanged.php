<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Events;

use Banyan\FilamentMediaLibrary\Models\MediaAsset;

/** The point every crop keeps was moved. */
final readonly class FocalPointChanged extends AssetEvent
{
    public const string NAME = 'media.asset.refocused';

    public function __construct(
        public MediaAsset $asset,
        string $actor,
    ) {
        parent::__construct($asset->id, $actor);
    }

    public function payload(): array
    {
        return [
            ...$this->reference($this->asset),
            'focal_x' => $this->asset->focal_x,
            'focal_y' => $this->asset->focal_y,
        ];
    }
}
