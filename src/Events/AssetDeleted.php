<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Events;

use Banyan\FilamentMediaLibrary\Enums\MediaKind;

/**
 * An asset, its versions and its renditions were removed.
 *
 * An asset that is in use cannot be deleted, so `usageCount` is always 0. The
 * property and the `usage_count` payload key stay so existing listeners keep working.
 */
final readonly class AssetDeleted extends AssetEvent
{
    public const string NAME = 'media.asset.deleted';

    public function __construct(
        int $assetId,
        public string $uuid,
        public int $usageCount,
        string $actor,
        public MediaKind $kind = MediaKind::Image,
        public ?int $folderId = null,
    ) {
        parent::__construct($assetId, $actor);
    }

    public function payload(): array
    {
        return [
            'asset_id' => $this->assetId,
            'uuid' => $this->uuid,
            'kind' => $this->kind->value,
            'folder_id' => $this->folderId,
            'usage_count' => $this->usageCount,
        ];
    }
}
