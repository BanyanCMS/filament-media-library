<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Rendering;

use Banyan\FilamentMediaLibrary\Exceptions\UnreadableImage;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use Throwable;

/**
 * The only place in the library that decodes or encodes image bytes.
 *
 * Wrapping intervention/image rather than calling it from three surfaces keeps
 * the focal-point arithmetic in one place — and that arithmetic is the whole
 * reason this class exists. intervention's own `cover()` takes a nine-point
 * position ('top-left' … 'bottom-right'); a focal point is a continuous
 * fraction, so the crop rectangle is computed here and handed to `crop()`
 * directly.
 */
final readonly class ImageProcessor
{
    /** Formats where a quality option means something. */
    private const array LOSSY = ['image/jpeg', 'image/webp', 'image/avif'];

    public function __construct(
        private ImageManager $manager,
        private int $quality = 82,
    ) {}

    /**
     * Intrinsic dimensions, or null when the bytes are not a decodable image.
     *
     * @return array{0: int, 1: int}|null
     */
    public function dimensions(string $binary): ?array
    {
        try {
            $image = $this->manager->read($binary);
        } catch (Throwable) {
            return null;
        }

        return [$image->width(), $image->height()];
    }

    /**
     * Produce the derivative described by the spec, cropping around the focal
     * point when the spec crops.
     *
     * @param  float  $focalX  0–1 fraction of the width
     * @param  float  $focalY  0–1 fraction of the height
     *
     * @throws UnreadableImage
     */
    public function render(
        string $binary,
        RenditionSpec $spec,
        float $focalX,
        float $focalY,
        string $mediaType,
    ): string {
        try {
            $image = $this->manager->read($binary);
        } catch (Throwable) {
            throw UnreadableImage::make($mediaType);
        }

        if ($spec->crops()) {
            /** @var int $width */
            $width = $spec->width;
            /** @var int $height */
            $height = $spec->height;

            $this->cropToFocalPoint($image, $width, $height, $focalX, $focalY);
            $image->resize($width, $height);
        } else {
            // scaleDown, not resize: asking for a 2000px wide rendition of an
            // 800px original should not upscale it into a blurrier, larger file.
            $image->scaleDown($spec->width, $spec->height);
        }

        $options = in_array($mediaType, self::LOSSY, true) ? ['quality' => $this->quality] : [];

        return (string) $image->encodeByMediaType($mediaType, ...$options);
    }

    /**
     * Cut the largest rectangle of the target aspect ratio that fits inside the
     * source, centred on the focal point and pushed back inside the bounds when
     * the focal point sits near an edge.
     *
     * Clamping rather than letting the rectangle hang off the edge is what makes
     * a focal point at (0.95, 0.05) — a logo in the top-right corner — produce a
     * crop that contains the logo, instead of one padded with background.
     */
    private function cropToFocalPoint(
        ImageInterface $image,
        int $targetWidth,
        int $targetHeight,
        float $focalX,
        float $focalY,
    ): void {
        $sourceWidth = $image->width();
        $sourceHeight = $image->height();

        $targetRatio = $targetWidth / $targetHeight;
        $sourceRatio = $sourceWidth / $sourceHeight;

        if ($sourceRatio > $targetRatio) {
            $cropHeight = $sourceHeight;
            $cropWidth = (int) round($sourceHeight * $targetRatio);
        } else {
            $cropWidth = $sourceWidth;
            $cropHeight = (int) round($sourceWidth / $targetRatio);
        }

        $cropWidth = max(1, min($cropWidth, $sourceWidth));
        $cropHeight = max(1, min($cropHeight, $sourceHeight));

        $offsetX = (int) round($this->fraction($focalX) * $sourceWidth - $cropWidth / 2);
        $offsetY = (int) round($this->fraction($focalY) * $sourceHeight - $cropHeight / 2);

        $offsetX = max(0, min($offsetX, $sourceWidth - $cropWidth));
        $offsetY = max(0, min($offsetY, $sourceHeight - $cropHeight));

        $image->crop($cropWidth, $cropHeight, $offsetX, $offsetY);
    }

    private function fraction(float $value): float
    {
        return max(0.0, min(1.0, $value));
    }
}
