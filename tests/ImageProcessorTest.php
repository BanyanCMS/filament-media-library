<?php

declare(strict_types=1);

use Banyan\FilamentMediaLibrary\Exceptions\UnreadableImage;
use Banyan\FilamentMediaLibrary\Rendering\ImageProcessor;
use Banyan\FilamentMediaLibrary\Rendering\RenditionSpec;
use Banyan\FilamentMediaLibrary\Tests\Support\Images;
use Illuminate\Image\ImageManager;

covers(ImageProcessor::class);

it('reads dimensions through Laravel image and returns null for bytes that are not an image', function (): void {
    $processor = app(ImageProcessor::class);

    expect($processor->dimensions(Images::splitPng(400, 200)))->toBe([400, 200])
        ->and($processor->dimensions('not an image'))->toBeNull();
});

it('crops around the focal point and keeps the original format', function (): void {
    $processor = new ImageProcessor(app(ImageManager::class), 82, 'gd');
    $png = Images::splitPng(400, 200);

    $right = $processor->render($png, RenditionSpec::make(100, 100, 'crop', 4096), 0.9, 0.5, 'image/png');
    $left = $processor->render($png, RenditionSpec::make(100, 100, 'crop', 4096), 0.1, 0.5, 'image/png');

    expect(getimagesizefromstring($right))->toMatchArray([0 => 100, 1 => 100, 'mime' => 'image/png'])
        ->and(Images::pixelAt($right, 0.5))->toBe([0, 0, 255])
        ->and(Images::pixelAt($left, 0.5))->toBe([255, 0, 0]);
});

it('defers to the app default driver when none is configured, and never upscales', function (): void {
    config()->set('images.default', 'gd');
    $processor = new ImageProcessor(app(ImageManager::class), 82, null);

    $scaled = $processor->render(Images::splitPng(400, 200), RenditionSpec::make(2000, null, null, 4096), 0.5, 0.5, 'image/png');

    expect(getimagesizefromstring($scaled))->toMatchArray([0 => 400, 1 => 200]);
});

it('reports bytes it cannot render as an unreadable image', function (): void {
    expect(fn () => app(ImageProcessor::class)->render('nope', RenditionSpec::make(10, 10, 'crop', 4096), 0.5, 0.5, 'image/png'))
        ->toThrow(UnreadableImage::class);
});
