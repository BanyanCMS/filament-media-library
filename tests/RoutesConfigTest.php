<?php

declare(strict_types=1);

use Banyan\FilamentMediaLibrary\Delivery\DeliveryRoutes;

covers(DeliveryRoutes::class);

it('ships the four routes under media-library.routes', function (): void {
    $routes = require __DIR__.'/../config/media-library.php';

    expect(array_keys($routes['routes']))->toBe(['render', 'file', 'restricted_file', 'restricted_render'])
        ->and($routes)->not->toHaveKey('route')
        ->and($routes['delivery'])->not->toHaveKeys(['file_route', 'restricted_file_route', 'restricted_render_route']);

    foreach ($routes['routes'] as $route) {
        expect(array_keys($route))->toBe(['path', 'name'])
            ->and($route['path'])->toContain('{uuid}');
    }
});

it('reads every route name and path from media-library.routes', function (): void {
    config(['media-library.routes' => [
        'render' => ['path' => 'img/{uuid}', 'name' => 'x.render'],
        'file' => ['path' => 'f/{uuid}', 'name' => 'x.file'],
        'restricted_file' => ['path' => 'rf/{uuid}', 'name' => 'x.rfile'],
        'restricted_render' => ['path' => 'rr/{uuid}', 'name' => 'x.rrender'],
    ]]);

    expect(DeliveryRoutes::renderPath())->toBe('img/{uuid}')
        ->and(DeliveryRoutes::renderName())->toBe('x.render')
        ->and(DeliveryRoutes::filePath())->toBe('f/{uuid}')
        ->and(DeliveryRoutes::fileName())->toBe('x.file')
        ->and(DeliveryRoutes::restrictedFilePath())->toBe('rf/{uuid}')
        ->and(DeliveryRoutes::restrictedFileName())->toBe('x.rfile')
        ->and(DeliveryRoutes::restrictedRenderPath())->toBe('rr/{uuid}')
        ->and(DeliveryRoutes::restrictedRenderName())->toBe('x.rrender');
});

it('falls back to the default names when the config is empty', function (): void {
    config(['media-library.routes' => []]);

    expect(DeliveryRoutes::renderName())->toBe('media-library.render')
        ->and(DeliveryRoutes::fileName())->toBe('media-library.file')
        ->and(DeliveryRoutes::restrictedFileName())->toBe('media-library.restricted-file')
        ->and(DeliveryRoutes::restrictedRenderName())->toBe('media-library.restricted-render');
});
