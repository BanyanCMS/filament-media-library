<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Events;

/** An asset changed folder. Null on either side is the library root. */
final readonly class AssetMoved implements MediaAssetEvent
{
    use AssetSubject;

    public function __construct(
        public int $id,
        public ?int $fromFolderId,
        public ?int $toFolderId,
        public string $actor,
    ) {}

    public function name(): string
    {
        return 'media.asset.moved';
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
            'from_folder_id' => $this->fromFolderId,
            'to_folder_id' => $this->toFolderId,
        ];
    }
}
