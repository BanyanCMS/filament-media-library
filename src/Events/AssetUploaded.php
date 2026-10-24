<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Events;

use Banyan\FilamentMediaLibrary\Models\MediaAsset;

/** A new asset and its first version were stored. */
final readonly class AssetUploaded extends AssetEvent
{
    public const string NAME = 'media.asset.uploaded';

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
            'mime_type' => $this->asset->mime_type,
            'size_bytes' => $this->asset->size_bytes,
            'width' => $this->asset->width,
            'height' => $this->asset->height,
        ];
    }
}
