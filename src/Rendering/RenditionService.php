<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Rendering;

use Banyan\FilamentMediaLibrary\Exceptions\UnreadableImage;
use Banyan\FilamentMediaLibrary\Models\MediaAsset;
use Banyan\FilamentMediaLibrary\Storage\MediaRepository;
use Banyan\FilamentMediaLibrary\Support\MediaConfig;
use Illuminate\Support\Facades\URL;

/**
 * Derivatives, on demand and cached.
 *
 * Generated on first request rather than pre-generated at upload, because the
 * set of sizes a site needs is a property of its templates, which change far
 * more often than its assets. Pre-generation forces a guess up front, wastes
 * storage on the sizes the guess got wrong, and needs a backfill every time a
 * template introduces a new one. On-demand needs neither: the first request for
 * a size pays for it once, and every request after is a disk read.
 *
 * There is deliberately no queue here. A queued pre-generation pipeline is a
 * different feature with its own failure modes (a dead worker means missing
 * images, silently), and it is not needed to make the on-demand path correct.
 */
final readonly class RenditionService
{
    public function __construct(
        private MediaRepository $media,
        private ImageProcessor $processor,
        private int $maxDimension = 4096,
    ) {}

    /**
     * The cached derivative's bytes, generating it if this is the first ask.
     *
     * @return array{0: string, 1: string}|null contents and media type, or null
     *                                          when the original has gone missing beneath us
     *
     * @throws UnreadableImage
     */
    public function render(MediaAsset $asset, RenditionSpec $spec): ?array
    {
        $original = $this->media->contents($asset);

        if ($original === null) {
            return null;
        }

        if ($spec->isPassthrough()) {
            return [$original, $asset->mime_type];
        }

        $disk = $this->media->disk();
        $path = $this->path($asset, $spec);

        if ($disk->exists($path)) {
            $cached = $disk->get($path);

            if (is_string($cached)) {
                return [$cached, $asset->mime_type];
            }
        }

        $rendered = $this->processor->render(
            $original,
            $spec,
            $asset->focal_x,
            $asset->focal_y,
            $asset->mime_type,
        );

        $disk->put($path, $rendered);

        return [$rendered, $asset->mime_type];
    }

    /**
     * The deterministic cache path for one asset/spec pair.
     *
     * The version id and the focal point are both in the name. That is what
     * makes invalidation a non-event: replacing the file or moving the
     * crosshair changes the path, so the stale derivative is unreachable the
     * instant the asset row is saved — no cache-busting query string, no purge
     * that can be forgotten, and no window where a page shows the old crop.
     */
    public function path(MediaAsset $asset, RenditionSpec $spec): string
    {
        $name = sprintf(
            'v%d-%sx%s-%s-%s-%s',
            $asset->current_version_id ?? 0,
            $spec->width ?? 'auto',
            $spec->height ?? 'auto',
            $spec->fit,
            $this->focalSegment($asset->focal_x),
            $this->focalSegment($asset->focal_y),
        );

        return sprintf(
            '%s/%s/%s.%s',
            $this->media->renditionsPath(),
            $asset->uuid,
            $name,
            $this->extension($asset->mime_type),
        );
    }

    /**
     * A signed URL for one derivative.
     *
     * Signed, not public: the parameters are an instruction to spend CPU and
     * disk, so they must be ones this application issued. Without a signature
     * the endpoint is an open image-resizing service attached to somebody
     * else's storage bill.
     */
    public function url(MediaAsset $asset, ?int $width = null, ?int $height = null, ?string $fit = null): string
    {
        $spec = RenditionSpec::make($width, $height, $fit, $this->maxDimension);

        return URL::signedRoute(MediaConfig::string('route.name', 'media-library.render'), array_filter([
            'uuid' => $asset->uuid,
            'w' => $spec->width,
            'h' => $spec->height,
            'fit' => $spec->fit,
            // The same version and focal point as the cache path: replacing the file or moving the
            // crosshair gives a new address, so a browser or CDN never keeps showing the old pixels
            // under the year-long immutable cache header.
            'v' => sprintf('%d-%s-%s', $asset->current_version_id ?? 0, $this->focalSegment($asset->focal_x), $this->focalSegment($asset->focal_y)),
        ], static fn (mixed $value): bool => $value !== null));
    }

    public function spec(?int $width, ?int $height, ?string $fit): RenditionSpec
    {
        return RenditionSpec::make($width, $height, $fit, $this->maxDimension);
    }

    /** Three decimal places is one part in a thousand of the image — finer than any crosshair can express. */
    private function focalSegment(float $value): string
    {
        return str_replace('.', '', number_format(max(0.0, min(1.0, $value)), 3, '.', ''));
    }

    private function extension(string $mimeType): string
    {
        return match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/avif' => 'avif',
            default => 'img',
        };
    }
}
