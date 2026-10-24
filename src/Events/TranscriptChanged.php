<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Events;

use Banyan\FilamentMediaLibrary\Models\MediaAsset;

/** The transcript of a video or audio file was written, edited or cleared. Whether it exists is recorded, never its text. */
final readonly class TranscriptChanged extends AssetEvent
{
    public const string NAME = 'media.asset.transcript_changed';

    public function __construct(
        public MediaAsset $asset,
        public bool $hasTranscript,
        string $actor,
    ) {
        parent::__construct($asset->id, $actor);
    }

    public function payload(): array
    {
        return [
            ...$this->reference($this->asset),
            'has_transcript' => $this->hasTranscript,
        ];
    }
}
