<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Events;

use Banyan\FilamentMediaLibrary\Models\MediaAsset;

/** The transcript of a video or audio file was written, edited or cleared. Whether it exists is recorded, never its text. */
final readonly class TranscriptChanged implements MediaAssetEvent
{
    use AssetSubject;

    public function __construct(
        public MediaAsset $asset,
        public bool $hasTranscript,
        public string $actor,
    ) {}

    public function name(): string
    {
        return 'media.asset.transcript_changed';
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
            'has_transcript' => $this->hasTranscript,
        ];
    }
}
