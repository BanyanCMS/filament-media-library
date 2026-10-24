<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Events;

use Banyan\FilamentMediaLibrary\Models\MediaAsset;

/** The asset's tags were replaced. */
final readonly class AssetTagged extends AssetEvent
{
    public const string NAME = 'media.asset.tagged';

    public function __construct(
        public MediaAsset $asset,
        public int $tagCount,
        string $actor,
    ) {
        parent::__construct($asset->id, $actor);
    }

    public function payload(): array
    {
        return [
            ...$this->reference($this->asset),
            'tag_count' => $this->tagCount,
        ];
    }
}
