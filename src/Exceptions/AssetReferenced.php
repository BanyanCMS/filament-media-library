<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Exceptions;

/**
 * A media asset still has live content references.
 *
 * Deleting the bytes would turn every current reference into a broken image.
 * The count is intentionally the only context carried by the exception; the
 * asset's filename and alt text can contain personal data.
 */
final class AssetReferenced extends MediaException
{
    public function __construct(
        public readonly int $assetId,
        public readonly int $usageCount,
    ) {
        parent::__construct(sprintf(
            'Media asset [%d] is still referenced by %d live content %s. Remove those references before deleting it.',
            $assetId,
            $usageCount,
            $usageCount === 1 ? 'location' : 'locations',
        ));
    }
}
