# banyancms/filament-media-library

**[READ ONLY]** Subtree split of the `filament-media-library` package from [BanyanCMS/banyan](https://github.com/BanyanCMS/banyan).

A governed image library for any Filament v5 panel. Every asset has alt text. Replacing a file adds a
version and keeps the old one. Crops follow a focal point an editor sets. Licences expire and the
library says so before they do. An asset that is still used cannot be deleted. Every change is a
Laravel event you can write to your own audit log.

It is the media library of [Banyan CMS](https://github.com/BanyanCMS/banyan), and it needs nothing
from Banyan.

> Status: 0.x, extracted from Banyan in SPEC-0102 M1. The form-field picker and a full usage guide
> with screenshots come next (M2, M3).

## Requirements

- PHP 8.3+, Laravel 13, Filament v5, Livewire v4.
- `ext-gd` (default) or `ext-imagick`. Image work uses `intervention/image` v3.

## Installation

```bash
composer require banyancms/filament-media-library
php artisan migrate
php artisan filament:assets
```

Add the plugin to a panel:

```php
use Banyan\FilamentMediaLibrary\MediaLibraryPlugin;

public function panel(Panel $panel): Panel
{
    return $panel
        // ...
        ->plugin(MediaLibraryPlugin::make());
}
```

The panel now has **Media library** (`/admin/media`) and a page per asset (`/admin/media/{uuid}`).

## Who may do what

Two Laravel Gate abilities. Their names are in `media-library.abilities`.

| Ability | Default name | Allows | When it is not defined |
| --- | --- | --- | --- |
| view | `media.view` | Open the library and asset pages | Any signed-in user |
| manage | `media.manage` | Upload, replace, edit details, refocus, retag, delete | **Nobody** |

Managing is refused until you define it. An undefined permission must never default to open:

```php
Gate::define('media.manage', fn (User $user): bool => $user->is_editor);
```

If your permissions do not live in a Gate ability, give the library a fallback:

```php
MediaLibraryGate::manageUsing(fn (): bool => auth()->user()?->hasRole('editor') === true);
```

Every write re-checks the gate on the server, so a hidden button is not the only protection.

## Writing from code

All writes go through `MediaGovernanceService`. Each runs in a transaction and announces itself
with an event:

```php
use Banyan\FilamentMediaLibrary\Governance\MediaGovernanceService;

$media = app(MediaGovernanceService::class);

$asset = $media->upload($bytes, 'campus.jpg', 'image/jpeg', 'The campus at dusk', (string) $user->id);
$media->setFocalPoint($asset, 0.7, 0.4, (string) $user->id);
$media->syncTags($asset, ['campus', 'events'], (string) $user->id);
```

Alt text is required. An empty one throws `AltTextRequired`. Bytes that are not an image throw
`UnreadableImage`. Deleting an asset that is still used throws `AssetReferenced`.

## Showing an image

```php
use Banyan\FilamentMediaLibrary\Rendering\RenditionService;

$url = app(RenditionService::class)->url($asset, 1200, 630, 'crop');
```

The URL is signed. The first request makes the rendition around the focal point and caches it on the
disk. Replacing the file or moving the focal point changes the URL, so browsers and CDNs never show
the old pixels. The route is `media/{uuid}/render`, named `media-library.render`. Both are
configurable.

In a Filament table, `MediaThumbnailColumn::make('hero_id')` shows the thumbnail of an asset id.

## Audit log: events

Every change dispatches an event that implements `Banyan\FilamentMediaLibrary\Events\MediaEvent`:

| Event | `name()` |
| --- | --- |
| `AssetUploaded` | `media.asset.uploaded` |
| `AssetReplaced` | `media.asset.replaced` |
| `AssetUpdated` | `media.asset.updated` |
| `FocalPointChanged` | `media.asset.refocused` |
| `AssetTagged` | `media.asset.tagged` |
| `AssetDeleted` | `media.asset.deleted` |

Events are dispatched inside the write's transaction. If a listener throws, the write is rolled
back, so an audit log never describes a change that did not happen. Payloads carry ids, sizes and
flags. They never carry alt text or a filename, which can name a person.

```php
Event::listen(MediaEvent::class, function (MediaEvent $event): void {
    AuditLog::create([
        'event' => $event->name(),
        'actor_id' => $event->actorId(),
        'subject_id' => $event->assetId(),
        'payload' => $event->payload(),
    ]);
});
```

## Where an asset is used

The library blocks deletion, counts "still used" licences and lists usages on the asset page. To do
this, it asks a `UsageResolver`. The default resolver reads the rows `UsageRecorder` writes:

```php
use Banyan\FilamentMediaLibrary\Usage\UsageRecorder;

// When a post is saved:
app(UsageRecorder::class)->sync($post, 'hero', [$post->hero_id]);

// When a post is deleted:
app(UsageRecorder::class)->forget($post);
```

If your app already knows its references, bind your own
`Banyan\FilamentMediaLibrary\Contracts\UsageResolver` in a service provider. Banyan does this,
because it reads usages from its own content tables.

## Configuration

Publish the config with `php artisan vendor:publish --tag=media-library-config`.

| Key | Default | Meaning |
| --- | --- | --- |
| `disk` | `public` (`MEDIA_LIBRARY_DISK`) | Laravel disk for originals and renditions |
| `paths.originals` / `paths.renditions` | `media` / `media/renditions` | Prefixes on that disk |
| `driver` | `gd` (`MEDIA_LIBRARY_DRIVER`) | `gd` or `imagick` |
| `uploads.max_size` | `12288` | Kilobytes |
| `uploads.mime_types` | jpeg, png, gif, webp, avif | Accepted uploads |
| `renditions.max_dimension` / `quality` | `4096` / `82` | Rendition limits |
| `expiry_warning_days` | `30` | Warn this long before a licence lapses |
| `tables.*` | `media_assets`, `media_versions`, `media_tags`, `media_asset_tag`, `media_usages` | Table names |
| `migrations` | `true` | Set `false` to skip the package's migrations |
| `abilities.view` / `abilities.manage` | `media.view` / `media.manage` | Gate abilities |
| `route.path` / `route.name` | `media/{uuid}/render` / `media-library.render` | Rendition route |
| `navigation.register` / `group` / `sort` | `true` / `null` / `20` | Sidebar item |

Change table names before the first migration runs.

## Translations

English, Traditional Chinese (`zh-Hant`) and Simplified Chinese (`zh-Hans`). Publish them with
`--tag=media-library-translations`.

## Testing

```bash
composer test
```

The suite boots a bare Laravel app with Filament and this package only. Its table names carry a
`lib_` prefix, so any query that ignores the table config fails.

## Licence

MIT. See [LICENSE](LICENSE).
