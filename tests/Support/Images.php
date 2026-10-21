<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Tests\Support;

use Banyan\FilamentMediaLibrary\Governance\MediaGovernanceService;
use Banyan\FilamentMediaLibrary\Models\MediaAsset;
use DateTimeImmutable;
use GdImage;
use RuntimeException;

/** Generated, not committed: a binary fixture is a thing nobody can review. */
final class Images
{
    /** A PNG whose left half is red and right half blue, so a crop is checked by one pixel. */
    public static function splitPng(int $width = 400, int $height = 200): string
    {
        $image = imagecreatetruecolor($width, $height);

        if (! $image instanceof GdImage) {
            throw new RuntimeException('GD could not create the fixture image.');
        }

        imagefilledrectangle($image, 0, 0, intdiv($width, 2) - 1, $height - 1, (int) imagecolorallocate($image, 255, 0, 0));
        imagefilledrectangle($image, intdiv($width, 2), 0, $width - 1, $height - 1, (int) imagecolorallocate($image, 0, 0, 255));

        ob_start();
        imagepng($image);
        $binary = (string) ob_get_clean();
        imagedestroy($image);

        return $binary;
    }

    /** @return array{0: int, 1: int, 2: int} */
    public static function pixelAt(string $binary, float $fractionX, float $fractionY = 0.5): array
    {
        $image = imagecreatefromstring($binary);

        if (! $image instanceof GdImage) {
            throw new RuntimeException('Not a decodable image.');
        }

        $rgb = imagecolorat($image, min((int) floor(imagesx($image) * $fractionX), imagesx($image) - 1), min((int) floor(imagesy($image) * $fractionY), imagesy($image) - 1));
        imagedestroy($image);

        return [($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF];
    }

    public static function upload(string $alt = 'A red and blue split', string $actor = '1', ?string $expires = null): MediaAsset
    {
        return app(MediaGovernanceService::class)->upload(
            self::splitPng(),
            'split.png',
            'image/png',
            $alt,
            $actor,
            $expires === null ? null : new DateTimeImmutable($expires),
        );
    }
}
