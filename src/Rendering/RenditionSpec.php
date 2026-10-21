<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Rendering;

/**
 * One request for a derivative: a box and how to fill it.
 *
 * Clamped on construction rather than validated at the controller, because the
 * render route is a public, unauthenticated (if signed) resource amplifier —
 * `?w=100000` must cost nothing more than `?w=4096`, and it must cost that in
 * every caller, not only in the one that remembered to check.
 */
final readonly class RenditionSpec
{
    public const string FIT_CROP = 'crop';

    public const string FIT_CONTAIN = 'contain';

    private function __construct(
        public ?int $width,
        public ?int $height,
        public string $fit,
    ) {}

    public static function make(?int $width, ?int $height, ?string $fit, int $maxDimension = 4096): self
    {
        $width = self::clamp($width, $maxDimension);
        $height = self::clamp($height, $maxDimension);

        // Neither dimension asked for is a request for the original, expressed
        // as a spec so the caller has one shape to handle.
        if ($width === null && $height === null) {
            return new self(null, null, self::FIT_CONTAIN);
        }

        // A single dimension cannot be cropped to — there is no second edge to
        // crop against — so it always means "scale to this".
        $fit = ($width === null || $height === null)
            ? self::FIT_CONTAIN
            : ($fit === self::FIT_CONTAIN ? self::FIT_CONTAIN : self::FIT_CROP);

        return new self($width, $height, $fit);
    }

    /** Whether the source can be served untouched. */
    public function isPassthrough(): bool
    {
        return $this->width === null && $this->height === null;
    }

    public function crops(): bool
    {
        return $this->fit === self::FIT_CROP && $this->width !== null && $this->height !== null;
    }

    private static function clamp(?int $value, int $max): ?int
    {
        if ($value === null || $value < 1) {
            return null;
        }

        return min($value, $max);
    }
}
