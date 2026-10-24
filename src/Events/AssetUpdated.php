<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Events;

use Banyan\FilamentMediaLibrary\Models\MediaAsset;
use DateTimeInterface;

/** Alt text, title or licence expiry was edited. Whether the text changed is recorded, never its value. */
final readonly class AssetUpdated extends AssetEvent
{
    public const string NAME = 'media.asset.updated';

    public function __construct(
        public MediaAsset $asset,
        public bool $altTextChanged,
        public ?DateTimeInterface $licenseExpiresAt,
        string $actor,
        public bool $titleChanged = false,
    ) {
        parent::__construct($asset->id, $actor);
    }

    public function payload(): array
    {
        return [
            ...$this->reference($this->asset),
            'alt_text_changed' => $this->altTextChanged,
            'title_changed' => $this->titleChanged,
            'license_expires_at' => $this->licenseExpiresAt?->format(DATE_ATOM),
        ];
    }
}
