<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Rendering;

use Banyan\FilamentMediaLibrary\Contracts\PdfThumbnailer;
use Banyan\FilamentMediaLibrary\Models\MediaAsset;
use Banyan\FilamentMediaLibrary\Storage\MediaRepository;

/**
 * First-page thumbnails of PDFs, cached beside the image renditions.
 *
 * The path carries the version id, so a replaced PDF is a new thumbnail the
 * moment the row is saved; {@see MediaRepository::forgetRenditions()} removes the
 * whole directory on replace and delete. Served by the rendition route, which
 * hands PDFs here instead of to the image processor.
 */
final readonly class PdfThumbnailService
{
    private const array WIDTHS = [160, 320, 640];

    public function __construct(
        private MediaRepository $media,
        private PdfThumbnailer $thumbnailer,
        private RenditionService $renditions,
    ) {}

    /** Whether a thumbnail could be offered for this asset on this host. */
    public function supports(MediaAsset $asset): bool
    {
        return $asset->mime_type === 'application/pdf' && $this->thumbnailer->available();
    }

    /** A signed URL on the rendition route, or null when the asset has no thumbnail. */
    public function url(MediaAsset $asset, int $width = 320): ?string
    {
        return $this->supports($asset) ? $this->renditions->url($asset, $this->width($width)) : null;
    }

    /** PNG bytes, from the cache or freshly rendered; null when none can be made. */
    public function render(MediaAsset $asset, ?int $width): ?string
    {
        if (! $this->supports($asset)) {
            return null;
        }

        $disk = $this->media->diskFor($asset);
        $path = sprintf('%s/%s/v%d-pdf-%d.png', $this->media->renditionsPath(), $asset->uuid, $asset->current_version_id ?? 0, $this->width($width ?? 320));

        if ($disk->exists($path)) {
            $cached = $disk->get($path);

            if (is_string($cached)) {
                return $cached;
            }
        }

        $original = $this->media->contents($asset);
        $png = $original === null ? null : $this->thumbnailer->thumbnail($original, $this->width($width ?? 320));

        if ($png !== null) {
            $disk->put($path, $png);
        }

        return $png;
    }

    /** Only a few widths, so a signed URL cannot be turned into an unbounded set of cache files. */
    private function width(int $requested): int
    {
        foreach (self::WIDTHS as $width) {
            if ($requested <= $width) {
                return $width;
            }
        }

        return self::WIDTHS[count(self::WIDTHS) - 1];
    }
}
