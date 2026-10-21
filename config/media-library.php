<?php

declare(strict_types=1);

return [
    /*
     * The filesystem disk originals and renditions are written to. Laravel's
     * filesystem abstraction, nothing bespoke: point it at an S3 disk and
     * nothing else changes.
     */
    'disk' => env('MEDIA_LIBRARY_DISK', 'public'),

    /* Directory prefixes on that disk. */
    'paths' => [
        'originals' => 'media',
        'renditions' => 'media/renditions',
    ],

    /*
     * The driver of Laravel's image component (Illuminate\Image): 'gd' (ext-gd)
     * or 'imagick' (ext-imagick). Null uses the app's own `images.default`.
     */
    'driver' => env('MEDIA_LIBRARY_DRIVER', 'gd'),

    'uploads' => [
        /* Kilobytes, matching Filament's and Laravel's `max:` rule. */
        'max_size' => 12288,
        'mime_types' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'],
    ],

    'renditions' => [
        /* Refuse absurd sizes: a rendition endpoint is a resource amplifier. */
        'max_dimension' => 4096,
        'quality' => 82,
    ],

    /* The library warns this many days before a licence lapses. */
    'expiry_warning_days' => 30,

    /*
     * Table names. Change them before the first migration runs; an app that
     * already has media tables of its own can point these at them and set
     * 'migrations' to false.
     */
    'tables' => [
        'assets' => 'media_assets',
        'versions' => 'media_versions',
        'tags' => 'media_tags',
        'asset_tag' => 'media_asset_tag',
        'usages' => 'media_usages',
    ],

    /* Set false to skip this package's migrations (publish and edit them instead). */
    'migrations' => true,

    /*
     * Gate abilities. When an ability is not defined, viewing needs a signed-in
     * user and managing (upload, replace, refocus, retag, delete) is refused:
     * an undefined permission never defaults open.
     */
    'abilities' => [
        'view' => 'media.view',
        'manage' => 'media.manage',
    ],

    /* The signed rendition route every image URL points at. */
    'route' => [
        'path' => 'media/{uuid}/render',
        'name' => 'media-library.render',
    ],

    'navigation' => [
        'register' => true,
        'group' => null,
        'sort' => 20,
    ],
];
