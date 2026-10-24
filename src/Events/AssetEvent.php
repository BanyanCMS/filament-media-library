<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Events;

use Banyan\FilamentMediaLibrary\Models\MediaAsset;

/**
 * The shared part of every event about one asset.
 *
 * A subclass sets {@see NAME} and returns its own {@see payload()}. It never
 * carries alt text, a filename or any other free text.
 */
abstract readonly class AssetEvent implements MediaAssetEvent
{
    /** A stable dotted name, e.g. `media.asset.uploaded`. Every subclass sets its own. */
    public const string NAME = '';

    public function __construct(
        public int $assetId,
        public string $actor,
    ) {}

    public function name(): string
    {
        return static::NAME;
    }

    public function subjectType(): string
    {
        return 'media_asset';
    }

    public function subjectId(): string
    {
        return (string) $this->assetId;
    }

    public function assetId(): int
    {
        return $this->assetId;
    }

    public function actorId(): string
    {
        return $this->actor;
    }

    /**
     * The four references every asset payload starts with.
     *
     * @return array{asset_id: int, uuid: string, kind: string, folder_id: int|null}
     */
    protected function reference(MediaAsset $asset): array
    {
        return [
            'asset_id' => $asset->id,
            'uuid' => $asset->uuid,
            'kind' => $asset->kind->value,
            'folder_id' => $asset->folder_id,
        ];
    }
}
