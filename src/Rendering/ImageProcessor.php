<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Rendering;

use Banyan\FilamentMediaLibrary\Exceptions\UnreadableImage;
use Illuminate\Image\Image;
use Illuminate\Image\ImageManager;
use Throwable;

/**
 * The only place in the library that decodes or encodes image bytes.
 *
 * Built on Laravel's own image component (`Illuminate\Image`, the `Image`
 * facade), so a host configures drivers the Laravel way and nothing here talks
 * to a driver directly. Keeping it in one class keeps the focal-point
 * arithmetic in one place. That arithmetic is the reason this class
 * exists: `cover()` only centres, while a focal point is a continuous
 * fraction, so the crop rectangle is computed here and handed to `crop()`.
 */
final readonly class ImageProcessor
{
    /**
     * @param  string|null  $driver  'gd' or 'imagick'; null uses Laravel's `images.default`
     */
    public function __construct(
        private ImageManager $images,
        private int $quality = 82,
        private ?string $driver = null,
    ) {}

    /**
     * Intrinsic dimensions, or null when the bytes are not a decodable image.
     *
     * @return array{0: int, 1: int}|null
     */
    public function dimensions(string $binary): ?array
    {
        try {
            return $this->read($binary)->dimensions();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Produce the derivative described by the spec, cropping around the focal
     * point when the spec crops. The output keeps the original's format.
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
            $image = $this->read($binary);
            [$sourceWidth, $sourceHeight] = $image->dimensions();

            if ($spec->crops()) {
                /** @var int<1, max> $width */
                $width = $spec->width;
                /** @var int<1, max> $height */
                $height = $spec->height;

                [$cropWidth, $cropHeight, $x, $y] = $this->focalCrop($sourceWidth, $sourceHeight, $width, $height, $focalX, $focalY);

                // Immutable: each transform returns a new Image.
                $image = $image->crop($cropWidth, $cropHeight, $x, $y)->resize($width, $height);
            } else {
                // scale() never upscales: asking for a 2000px wide rendition of
                // an 800px original should not make a blurrier, larger file.
                $image = $image->scale(
                    $spec->width === null ? null : max(1, $spec->width),
                    $spec->height === null ? null : max(1, $spec->height),
                );
            }

            return $image->quality(max(1, min(100, $this->quality)))->toBytes();
        } catch (Throwable) {
            throw UnreadableImage::make($mediaType);
        }
    }

    private function read(string $binary): Image
    {
        $image = $this->images->fromBytes($binary);

        return $this->driver === null ? $image : $image->using($this->driver);
    }

    /**
     * Cut the largest rectangle of the target aspect ratio that fits inside the
     * source, centred on the focal point and pushed back inside the bounds when
     * the focal point sits near an edge.
     *
     * Clamping rather than letting the rectangle hang off the edge is what makes
     * a focal point at (0.95, 0.05), a logo in the top-right corner, produce a
     * crop that contains the logo, instead of one padded with background.
     *
     * @return array{0: int<1, max>, 1: int<1, max>, 2: int, 3: int} width, height, x, y
     */
    private function focalCrop(
        int $sourceWidth,
        int $sourceHeight,
        int $targetWidth,
        int $targetHeight,
        float $focalX,
        float $focalY,
    ): array {
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

        return [$cropWidth, $cropHeight, $offsetX, $offsetY];
    }

    private function fraction(float $value): float
    {
        return max(0.0, min(1.0, $value));
    }
}
