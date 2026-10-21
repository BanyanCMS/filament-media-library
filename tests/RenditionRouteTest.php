<?php

declare(strict_types=1);

use Banyan\FilamentMediaLibrary\Governance\MediaGovernanceService;
use Banyan\FilamentMediaLibrary\Http\RenditionController;
use Banyan\FilamentMediaLibrary\Rendering\RenditionService;
use Banyan\FilamentMediaLibrary\Tests\Support\Images;

covers(RenditionController::class, RenditionService::class);

it('serves a focal-point crop from a signed URL on the configured route', function (): void {
    $asset = Images::upload();
    app(MediaGovernanceService::class)->setFocalPoint($asset, 0.9, 0.5, '1');

    $url = app(RenditionService::class)->url($asset->refresh(), 100, 100, 'crop');

    expect($url)->toContain('/media/'.$asset->uuid.'/render');

    $response = $this->get($url)->assertOk();

    // A square crop of a 2:1 image keeps the blue right half when the focus is there.
    expect($response->headers->get('Content-Type'))->toBe('image/png')
        ->and(Images::pixelAt((string) $response->getContent(), 0.5))->toBe([0, 0, 255]);
});

it('refuses an unsigned rendition request', function (): void {
    $asset = Images::upload();

    $this->get('/media/'.$asset->uuid.'/render?w=50')->assertForbidden();
});
