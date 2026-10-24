<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Events;

/** An asset changed folder. Null on either side is the library root. */
final readonly class AssetMoved extends AssetEvent
{
    public const string NAME = 'media.asset.moved';

    public function __construct(
        int $assetId,
        public ?int $fromFolderId,
        public ?int $toFolderId,
        string $actor,
    ) {
        parent::__construct($assetId, $actor);
    }

    public function payload(): array
    {
        return [
            'asset_id' => $this->assetId,
            'from_folder_id' => $this->fromFolderId,
            'to_folder_id' => $this->toFolderId,
        ];
    }
}
