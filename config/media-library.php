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
        /*
         * What each kind of file may be. Types are matched against
         * what the bytes are, never the extension or the browser's claim; a
         * type not listed here is refused. `max_size` is in kilobytes, matching
         * Filament's and Laravel's `max:` rule.
         *
         * The older flat `uploads.max_size` and `uploads.mime_types` keys still
         * work and mean the image kind when `kinds.image` is absent.
         */
        'kinds' => [
            'image' => [
                'mime_types' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'],
                'max_size' => 12288,
            ],
            'document' => [
                'mime_types' => [
                    'application/pdf',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                    'application/vnd.oasis.opendocument.text',
                    'application/vnd.oasis.opendocument.spreadsheet',
                    'application/vnd.oasis.opendocument.presentation',
                    'text/csv',
                    'text/plain',
                    // WebVTT, for a video's captions. Detected only
                    // when the file starts with WEBVTT.
                    'text/vtt',
                ],
                'max_size' => 51200,
            ],
            'video' => [
                'mime_types' => ['video/mp4', 'video/webm'],
                'max_size' => 512000,
            ],
            'audio' => [
                'mime_types' => ['audio/mpeg', 'audio/mp4', 'audio/wav'],
                'max_size' => 102400,
            ],
            'archive' => [
                'mime_types' => ['application/zip'],
                'max_size' => 102400,
            ],
        ],

        /* A zip with more entries than this, or over 1 GB uncompressed, is refused. */
        'archive' => ['max_entries' => 5000],

        /*
         * A class implementing Banyan\FilamentMediaLibrary\Contracts\FileScanner,
         * run on every upload before anything is written. Null scans nothing.
         * Scanning\ClamAvScanner is an opt-in example.
         */
        'scanner' => null,
    ],

    'delivery' => [
        /*
         * The signed route every non-image file (and every original) is served
         * from. `{uuid}` is required. Supports Range requests on local disks;
         * disks with temporary URLs (S3, R2) are redirected to one.
         */
        'file_route' => [
            'path' => 'media/{uuid}/file',
            'name' => 'media-library.file',
        ],

        /* How long the temporary URL a remote disk is redirected to stays valid. */
        'temporary_url_minutes' => 5,

        /*
         * When a disk can issue temporary URLs (S3, R2): 'downloads' redirects
         * downloads and top-level visits to one but streams embeds (the PDF
         * preview, <video>, <audio>) from this origin, so a strict CSP
         * (frame-src/media-src 'self') still allows them; 'always' or 'never'.
         */
        'redirect_to_storage' => 'downloads',

        /*
         * Files in members-only and password-protected folders:
         * their addresses expire after this many minutes, are only issued to a
         * user who may browse the folder, and are served by these routes, which
         * run the middleware below (they need the visitor's session) and check
         * access again on every request. The public routes above refuse them.
         */
        'restricted_url_minutes' => 10,
        'restricted_middleware' => ['web'],
        'restricted_file_route' => [
            'path' => 'media/{uuid}/restricted-file',
            'name' => 'media-library.restricted-file',
        ],
        'restricted_render_route' => [
            'path' => 'media/{uuid}/restricted-render',
            'name' => 'media-library.restricted-render',
        ],
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
        'folders' => 'media_folders',
        'folder_members' => 'media_folder_members',
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
        /*
         * The library administrator: sees and manages every folder, including
         * members-only and password-protected ones, without being a member or
         * unlocking, and may change any folder's access. Closed until defined.
         */
        'manage_all' => 'media.manage-all',
    ],

    /*
     * Folders and sub-folders. Set `enabled` to false for a flat
     * library: all folder UI is hidden and every asset reads as being at the root.
     * `max_depth` caps nesting; `unlock_minutes` is how long an unlocked
     * password-protected folder stays open. `member_label` is the user attribute
     * shown when choosing a folder's members (falls back to `email`).
     */
    'folders' => [
        'enabled' => true,
        'max_depth' => 8,
        'unlock_minutes' => 30,
        'member_label' => 'name',
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
