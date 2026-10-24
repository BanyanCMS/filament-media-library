<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Events;

use Banyan\FilamentMediaLibrary\Models\MediaAsset;
use Banyan\FilamentMediaLibrary\Models\MediaVersion;

/** A new file became the asset's current version; the old one is kept. */
final readonly class AssetReplaced extends AssetEvent
{
    public const string NAME = 'media.asset.replaced';

    public function __construct(
        public MediaAsset $asset,
        public MediaVersion $version,
        public ?int $supersededVersionId,
        string $actor,
    ) {
        parent::__construct($asset->id, $actor);
    }

    public function payload(): array
    {
        return [
            ...$this->reference($this->asset),
            'version_id' => $this->version->id,
            'superseded_version_id' => $this->supersededVersionId,
            'size_bytes' => $this->version->size_bytes,
        ];
    }
}
