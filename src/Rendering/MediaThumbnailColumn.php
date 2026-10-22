<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Rendering;

use Banyan\FilamentMediaLibrary\Models\MediaAsset;
use Filament\Tables\Columns\ImageColumn;
use Illuminate\Database\Eloquent\Model;

/**
 * An `ImageColumn` that reads an asset id and serves the asset's thumbnail.
 *
 * A subclass rather than `->state(...)` on a plain `ImageColumn`, because a
 * resource that computes its own column state (Banyan's content lists do, as
 * field values are not model attributes) would overwrite a URL installed
 * there. Translating id to URL at `getImageUrl()` puts the conversion after
 * whatever the resource decides the state is.
 *
 * A list state (a multiple media field) is handled for free: `ImageColumn`
 * calls `getImageUrl()` once per item.
 */
class MediaThumbnailColumn extends ImageColumn
{
    private int $thumbnailWidth = 80;

    private int $thumbnailHeight = 80;

    public function thumbnailSize(int $width, int $height): static
    {
        $this->thumbnailWidth = $width;
        $this->thumbnailHeight = $height;

        return $this;
    }

    public function getImageUrl(?string $state = null, ?Model $relatedRecord = null): ?string
    {
        $asset = self::assetFor($state);

        // A restricted image the signed-in user may not browse has no thumbnail, rather than a column that throws.
        return $asset instanceof MediaAsset && $asset->isImage() && $asset->isDeliverable()
            ? app(RenditionService::class)->url($asset, $this->thumbnailWidth, $this->thumbnailHeight, RenditionSpec::FIT_CROP)
            : null;
    }

    /** An empty reference, and a reference to a deleted asset, both render as nothing. */
    public static function assetFor(mixed $state): ?MediaAsset
    {
        if (is_array($state)) {
            $state = $state[0] ?? null;
        }

        if (! is_numeric($state) || (int) $state <= 0) {
            return null;
        }

        return MediaAsset::query()->find((int) $state);
    }
}
