# banyancms/filament-media-library

**[READ ONLY]** Subtree split of the `filament-media-library` package from [BanyanCMS/banyan](https://github.com/BanyanCMS/banyan).

A governed **image library for Filament v5**. Every asset has alt text. Replacing a file adds a version and keeps the old one. Crops follow a focal point that an editor sets. Licences expire, and the library warns before they do. An asset that is still used cannot be deleted. Every change is a Laravel event that you can write to your own audit log.

Editors manage images in one place, and pick them from any form with a `MediaPicker` field. Your site asks for an image by size and gets a signed, cached, focal-point-aware crop.

![The media library: a licence warning, search and tag filters, and a grid of assets. A padlock marks an asset that is still used, so it cannot be deleted](docs/images/library.png)

It is a standalone Filament plugin. It needs no other Banyan package. It was extracted from [Banyan CMS](https://github.com/BanyanCMS/banyan), whose media library runs on it. Licensed **MIT**.

> **Building a real feature?** Read the **[usage guide](docs/guide.md)**. It walks through a complete, tested example (articles with a hero image and a gallery): who may upload, putting images on a model, keeping "where is it used" correct, showing images on your site, an audit log, licence expiry, customising and testing.

> **Where to change it:** the source lives in the Banyan monorepo at `packages/filament-media-library`. This repository is its published split. Open issues and pull requests against the [monorepo](https://github.com/BanyanCMS/banyan).

---

## Contents

- [What you get](#what-you-get)
- [Requirements](#requirements)
- [Installation](#installation)
- [Quick start: an image on an article in five steps](#quick-start-an-image-on-an-article-in-five-steps)
- [Who may do what](#who-may-do-what)
- [The library and the asset page](#the-library-and-the-asset-page)
- [MediaPicker: choosing an image in a form](#mediapicker-choosing-an-image-in-a-form)
- [Where an asset is used](#where-an-asset-is-used)
- [Showing an image](#showing-an-image)
- [Writing from code](#writing-from-code)
- [Audit log: events](#audit-log-events)
- [Licence expiry](#licence-expiry)
- [Configuration](#configuration)
- [Existing tables and custom table names](#existing-tables-and-custom-table-names)
- [Look and feel, dark mode, languages](#look-and-feel-dark-mode-languages)
- [Exceptions](#exceptions)
- [Testing](#testing)
- [Running the example](#running-the-example)
- [FAQ and troubleshooting](#faq-and-troubleshooting)
- [Licence](#licence)

---

## What you get

- **A library page** (grid, search, tag filter, licence filters) and **a page per asset** (preview, details, focal point, usage, version history), added to a panel with one line.
- **Alt text is required**, at three levels: the form, the repository (a seeder or an import cannot skip it) and a `NOT NULL` column.
- **Versions.** Replacing a file appends a version and keeps the old file. Nothing is overwritten.
- **Focal point.** An editor clicks or drags on the image. Every crop keeps that point in frame.
- **Licence expiry.** An asset can carry an expiry date. The library shows how many licences have lapsed or are about to, and how many of those are still used.
- **Usage tracking and delete protection.** An asset that is used somewhere cannot be deleted. The asset page lists where it is used.
- **`MediaPicker`**, a form field that chooses (and, for editors, uploads) an asset anywhere in your forms.
- **Signed renditions.** `RenditionService::url()` returns a signed URL for any size. The image is made on first request and cached. Replacing the file or moving the focal point changes the URL.
- **`MediaThumbnailColumn`** for Filament tables.
- **Events for an audit log.** Six events, written inside the change's own transaction. Payloads never hold alt text or filenames.
- **Gate abilities** for access, closed by default for anything that changes the library.
- **Any Laravel disk**, configurable table names, English, Traditional Chinese and Simplified Chinese.
- **No queue, no scheduler, no JavaScript build.** Renditions are made on request. Expiry is computed when you read it.

![One asset: the focal point crosshair with two sample crops, details, where it is used and the version history](docs/images/asset.png)

## Requirements

- PHP 8.3+, Laravel 13, Filament v5, Livewire v4.
- `ext-gd` (default) or `ext-imagick`. Image work uses `intervention/image` v3.
- A database. Table names are configurable; see [Configuration](#configuration).

## Installation

```bash
composer require banyancms/filament-media-library
php artisan migrate
php artisan filament:assets
```

The service provider is discovered automatically. `filament:assets` publishes the library's stylesheet; run it again after updating the package. The migration creates five tables (`media_assets`, `media_versions`, `media_tags`, `media_asset_tag`, `media_usages`). If you want other names, or you already have these tables, read [Configuration](#configuration) **before** you run `migrate`.

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

The panel now has **Media library** at `/admin/media` (the sidebar item) and one page per asset at `/admin/media/{uuid}`.

**Storage.** Files go to the disk named by `media-library.disk` (default `public`, env `MEDIA_LIBRARY_DISK`). Originals and renditions are always served through the signed route, never by a URL straight to the disk. So the disk can be private and you do **not** need `php artisan storage:link`. Point the config at an S3 disk and nothing else changes.

## Quick start: an image on an article in five steps

This adds a hero image to an `Article` resource. It is the smallest useful path; the [guide](docs/guide.md) explains each step in depth.

### 1. Decide who may upload

Browsing the library and picking an image are open to any signed-in user. **Uploading, replacing, editing and deleting are refused until you define `media.manage`.** In `AppServiceProvider::boot()`:

```php
use Illuminate\Support\Facades\Gate;

Gate::define('media.manage', fn (User $user): bool => $user->is_editor);
```

### 2. Add a column that holds the asset id

```php
Schema::table('articles', function (Blueprint $table): void {
    $table->unsignedBigInteger('hero_id')->nullable();
});
```

The picker stores an **id**, not a path or a URL. For a gallery use a `json` column and cast it to `array`.

### 3. Put the picker in your resource

```php
use Banyan\FilamentMediaLibrary\Forms\MediaPicker;
use Banyan\FilamentMediaLibrary\Rendering\MediaThumbnailColumn;

// In the form
MediaPicker::make('hero_id')->label('Hero image')->thumbnails(),

// In the table
MediaThumbnailColumn::make('hero_id')->label('Hero'),
```

Remember to add `hero_id` to the model's `$fillable` (or use `$guarded = []`).

### 4. Record that the article uses the image

The picker stores ids only. It does not know which record it belongs to, so **your model has to tell the library what it uses**. Delete protection, the licence report and the "used by" table all read this. Without it the library thinks every asset is unused and lets editors delete it.

```php
use Banyan\FilamentMediaLibrary\Usage\UsageRecorder;

final class Article extends Model
{
    protected static function booted(): void
    {
        static::saved(function (self $article): void {
            app(UsageRecorder::class)->sync($article, 'hero_id', $article->hero_id === null ? [] : [(int) $article->hero_id]);
        });

        static::deleted(fn (self $article) => app(UsageRecorder::class)->forget($article));
    }
}
```

For more than one field, use the reusable trait from the example, [`RecordsMediaUsage`](examples/Articles/Concerns/RecordsMediaUsage.php).

### 5. Show the image

```php
use Banyan\FilamentMediaLibrary\Models\MediaAsset;
use Banyan\FilamentMediaLibrary\Rendering\RenditionService;

$hero = MediaAsset::find($article->hero_id);
$src = app(RenditionService::class)->url($hero, 1200, 630, 'crop');
```

```blade
<img src="{{ $src }}" width="1200" height="630" alt="{{ $hero->alt_text }}">
```

The alt text is the asset's own and is never empty. That is the whole feature working end to end.

![The Articles list with a thumbnail column](docs/images/articles-table.png)

---

## Who may do what

Two Laravel Gate abilities. Their names are in `media-library.abilities`.

| Ability | Default name | Allows | When it is not defined |
| --- | --- | --- | --- |
| view | `media.view` | Open the library and asset pages | Any signed-in user |
| manage | `media.manage` | Upload, replace, edit details, refocus, retag, delete (including the picker's "Upload new") | **Nobody** |

Managing is refused until you define it. An undefined permission must never default to open:

```php
Gate::define('media.manage', fn (User $user): bool => $user->is_editor);
```

If your permissions do not live in a Gate ability, give the library a fallback. It is asked **only while the manage ability is undefined**:

```php
use Banyan\FilamentMediaLibrary\Access\MediaLibraryGate;

MediaLibraryGate::manageUsing(fn (): bool => auth()->user()?->hasRole('editor') === true);
```

Both checks return `false` for a guest. Every write re-checks the gate on the server (page actions, the focal point save and the picker's upload all do), so a hidden button is not the only protection.

Two things to know:

- The library asks `Gate::allows()` first, so a `Gate::before()` grant counts (this is how permission packages such as spatie/laravel-permission and super-admin shortcuts work), even when `media.manage` was never `define()`d. Only when nothing grants it and the ability is undefined does `manageUsing()` decide.
- `MediaGovernanceService` and the other classes you call from code do **not** check the gate. Only the pages and the picker do. See [Writing from code](#writing-from-code).

A signed-in user who may not manage sees a read-only library:

![The library for a user without media.manage: no Upload button, and no delete or padlock icons on the cards](docs/images/non-editor.png)

## The library and the asset page

![The upload dialog: image file, alt text (required), licence expiry and tags](docs/images/upload.png)

- **Upload files** (managers only) asks for the file, the alt text (required), an optional licence expiry date and comma-separated tags. Tags are lower-cased on save.
- The grid shows the newest **120** assets first, with **Load more** for the next 120 and a "Showing 120 of N" count. The search box (filename or alt text), the tag filter and the licence filters narrow it.
- A card shows a trash icon when the asset is unused and a padlock when it is used. Click a card to open the asset page.
- On the asset page, **Edit details** changes alt text and licence expiry. **More** holds Edit tags, Replace file and, for an asset nothing uses, Delete asset. The focal point is set on the image itself.
- The **Version history** lists every file the asset has had. It is a record: there is no restore or download button in this release.

## MediaPicker: choosing an image in a form

```php
use Banyan\FilamentMediaLibrary\Forms\MediaPicker;

MediaPicker::make('hero_id');                       // one asset: stores an id
MediaPicker::make('gallery')->multiple();           // many assets: stores a list of ids
MediaPicker::make('hero_id')->thumbnails();         // a 48px cropped thumbnail beside each option
MediaPicker::make('hero_id')->uploadable(false);    // no "Upload new" button on this field
```

`MediaPicker` extends Filament's `Select`, so `->label()`, `->required()`, `->helperText()`, `->hint()`, `->live()`, `->maxItems()` and the other `Select` methods work as usual.

![The picker open: the chosen image on top, a search box and matching assets with thumbnails](docs/images/picker.png)

- **Options** are the newest 50 selectable assets. Typing searches filename and alt text and returns up to 50 matches.
- **Expired assets are not offered.** Choosing is where a new usage starts, and a lapsed licence is what expiry exists to stop. If a record already holds an asset that has since expired, it still shows, labelled "Licence expired".
- **Upload new** (the button at the end of the field) opens the same dialog as the library, requires alt text, goes through the same audited path, and selects the new asset. It is visible only to users who may manage, and the gate is checked again when the dialog is submitted. Wait for the file to finish uploading before you submit.
- **Thumbnails** are off by default. They turn every option into HTML (an `<img>` with the asset's alt text). The plain list is the cheapest thing for a screen reader and a slow connection.
- **Accessibility.** The picker is a standard searchable `Select`, so it has Filament's keyboard and screen-reader behaviour. The library does not ship a bespoke widget to keep conformant.

![The picker's upload dialog while a file is uploading](docs/images/picker-upload.png)

![After the upload: the new image is selected, and a "Asset uploaded." notice shows](docs/images/picker-uploaded.png)

The picker does not check the browse ability. Whoever can open the form that holds the field is already choosing which assets it shows.

## Where an asset is used

The library blocks deletion, counts "still used" licences and lists usages on the asset page. To do this it asks a `UsageResolver`. The default resolver reads the rows that `UsageRecorder` writes:

```php
use Banyan\FilamentMediaLibrary\Usage\UsageRecorder;

$recorder = app(UsageRecorder::class);

// When a post is saved: replace what its `hero` field uses (a list of asset ids).
$recorder->sync($post, 'hero', [$post->hero_id]);

// Per locale, if the field differs by language.
$recorder->sync($post, 'hero', [$post->hero_id], 'en');

// When a post is deleted.
$recorder->forget($post);
```

`sync()` replaces the assets one field of one model (and one locale) uses. `forget()` drops everything a model uses. The model must be saved and have an int or string key; otherwise `InvalidArgumentException`. Model events do not fire for query-builder updates or `insert()`, so a bulk import has to call `sync()` itself. The guide shows a reusable trait, a backfill, and the details.

If your app already knows its references, bind your own `Banyan\FilamentMediaLibrary\Contracts\UsageResolver` in a service provider. It has four methods:

```php
interface UsageResolver
{
    /** @return list<AssetUsage> */
    public function usages(int $assetId): array;           // the asset page's "Usage" table

    public function count(int $assetId): int;              // delete protection

    /** @return array<int, int> asset id => live usage count */
    public function counts(array $assetIds): array;        // one query for a library page

    public function whereUsed(Builder $query, string $assetIdColumn): void; // the licence report
}
```

A short example that reads an `article_media` pivot table directly, and links each usage to its edit page:

```php
use Banyan\FilamentMediaLibrary\Contracts\UsageResolver;
use Banyan\FilamentMediaLibrary\Usage\AssetUsage;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class ArticleUsageResolver implements UsageResolver
{
    public function usages(int $assetId): array
    {
        return DB::table('article_media as m')
            ->join('articles as a', 'a.id', '=', 'm.article_id')
            ->where('m.media_asset_id', $assetId)
            ->orderBy('a.id')
            ->get(['a.id', 'a.title', 'm.role'])
            ->map(fn (object $row): AssetUsage => new AssetUsage(
                label: 'Article: '.$row->title,
                type: 'Article',
                field: (string) $row->role,
                url: ArticleResource::getUrl('edit', ['record' => $row->id]),
            ))
            ->all();
    }

    public function count(int $assetId): int
    {
        return DB::table('article_media')->where('media_asset_id', $assetId)->count();
    }

    public function counts(array $assetIds): array
    {
        return DB::table('article_media')
            ->whereIn('media_asset_id', $assetIds)
            ->groupBy('media_asset_id')
            ->selectRaw('media_asset_id, COUNT(*) as n')
            ->pluck('n', 'media_asset_id')
            ->map(fn (mixed $n): int => (int) $n)
            ->all();
    }

    public function whereUsed(Builder $query, string $assetIdColumn): void
    {
        $query->whereExists(fn (Builder $q) => $q->selectRaw('1')->from('article_media as m')->whereColumn('m.media_asset_id', $assetIdColumn));
    }
}

// In a service provider's register():
$this->app->singleton(UsageResolver::class, ArticleUsageResolver::class);
```

"Used" must mean **live**. A usage whose owner has been deleted has to stop counting, or a safe delete is refused and a licence report raises a false alarm.

## Showing an image

```php
use Banyan\FilamentMediaLibrary\Rendering\RenditionService;

$renditions = app(RenditionService::class);

$renditions->url($asset, 1200, 630, 'crop');      // 1200x630, cropped around the focal point
$renditions->url($asset, 1200, 630, 'contain');   // fits inside 1200x630, no crop
$renditions->url($asset, 800);                    // 800px wide, height follows
$renditions->url($asset);                         // the original bytes
```

| Call | Result |
| --- | --- |
| width and height, `'crop'` (or no fit) | Cropped to that box around the focal point |
| width and height, `'contain'` | Scaled to fit inside the box, never upscaled |
| one dimension only | Scaled to that size, never upscaled (always `contain`) |
| neither | The original file, untouched |

Sizes are clamped to `renditions.max_dimension` (default 4096). The output keeps the original format (a JPEG stays a JPEG). Lossy formats use `renditions.quality`.

A srcset from three calls:

```blade
<img
    src="{{ $renditions->url($asset, 1200, 630, 'crop') }}"
    srcset="{{ $renditions->url($asset, 600, 315, 'crop') }} 600w, {{ $renditions->url($asset, 1200, 630, 'crop') }} 1200w"
    sizes="(min-width: 52rem) 52rem, 100vw"
    width="1200" height="630"
    alt="{{ $asset->alt_text }}">
```

![The public article: a 1200x630 hero cropped around its focal point, and a gallery of 400x300 crops](docs/images/front-end.png)

**The URL is signed.** The route `media/{uuid}/render` (named `media-library.render`, both configurable) uses Laravel's `signed` middleware, because its query string is an instruction to spend CPU and disk. It has no session and no cookies. A request with a missing or altered signature gets `403`. The signature does not expire, so a URL stays valid for as long as `APP_KEY` does.

**Caching and versioning.** The first request makes the rendition around the focal point and stores it on the disk. Every later request is a disk read. The response carries `Cache-Control: public, max-age=31536000, immutable`. The URL includes the current version and the focal point, so replacing the file or moving the focal point gives a **new** URL. Browsers and CDNs never show the old pixels. Cache the URLs freely, in your HTML cache too.

**Tables.** In a Filament table, `MediaThumbnailColumn::make('hero_id')` shows the thumbnail of an asset id (a list of ids shows each). `->thumbnailSize(96, 64)` changes the crop; the default is 80 by 80.

## Writing from code

All writes go through `MediaGovernanceService`. Each runs in a transaction and announces itself with an event:

```php
use Banyan\FilamentMediaLibrary\Governance\MediaGovernanceService;

$media = app(MediaGovernanceService::class);

$asset = $media->upload($bytes, 'campus.jpg', 'image/jpeg', 'The campus at dusk', (string) $user->id);
$media->updateDetails($asset, 'The campus at dusk, from the north gate', null, (string) $user->id);
$media->setFocalPoint($asset, 0.7, 0.4, (string) $user->id);
$media->syncTags($asset, ['campus', 'events'], (string) $user->id);
$media->replace($asset, $newBytes, 'campus-2027.jpg', 'image/jpeg', (string) $user->id);
$media->delete($asset, (string) $user->id);
```

| Method | Returns | Throws |
| --- | --- | --- |
| `upload(string $binary, string $filename, string $mimeType, string $altText, string $actorId, ?DateTimeInterface $licenseExpiresAt = null)` | `MediaAsset` | `AltTextRequired`, `UnreadableImage` |
| `replace(MediaAsset $asset, string $binary, string $filename, string $mimeType, string $actorId)` | `MediaVersion` | `UnreadableImage` |
| `updateDetails(MediaAsset $asset, string $altText, ?DateTimeInterface $licenseExpiresAt, string $actorId)` | `void` | `AltTextRequired` |
| `setFocalPoint(MediaAsset $asset, float $x, float $y, string $actorId)` | `void` | none (clamped to 0 to 1) |
| `syncTags(MediaAsset $asset, array $names, string $actorId)` | `void` | none |
| `delete(MediaAsset $asset, string $actorId)` | `void` | `AssetReferenced` |

`$actorId` is your user's id as a string. It goes into the event. `updateDetails()` sets the licence expiry to exactly what you pass, so `null` clears it. `uploaded_by` on the asset is set only when it is numeric. These methods do not check the gate: if the call is made on behalf of a user, check `MediaLibraryGate::allowsManage()` yourself.

## Audit log: events

Every change dispatches an event that implements `Banyan\FilamentMediaLibrary\Events\MediaEvent`. It has `name()`, `assetId()`, `actorId()` and `payload()`.

| Event class | `name()` | Payload keys |
| --- | --- | --- |
| `AssetUploaded` | `media.asset.uploaded` | `asset_id`, `uuid`, `mime_type`, `size_bytes`, `width`, `height` |
| `AssetReplaced` | `media.asset.replaced` | `asset_id`, `uuid`, `version_id`, `superseded_version_id`, `size_bytes` |
| `AssetUpdated` | `media.asset.updated` | `asset_id`, `uuid`, `alt_text_changed` (bool), `license_expires_at` (ISO 8601 or null) |
| `FocalPointChanged` | `media.asset.refocused` | `asset_id`, `uuid`, `focal_x`, `focal_y` |
| `AssetTagged` | `media.asset.tagged` | `asset_id`, `uuid`, `tag_count` |
| `AssetDeleted` | `media.asset.deleted` | `asset_id`, `uuid`, `usage_count` (the count at the moment of deletion) |

Events are dispatched **inside the write's database transaction**. If a listener throws, the write is rolled back, so an audit log never describes a change that did not happen. Payloads carry ids, sizes and flags. They never carry alt text or a filename, which can name a person.

```php
use Banyan\FilamentMediaLibrary\Events\MediaEvent;
use Illuminate\Support\Facades\Event;

Event::listen(MediaEvent::class, function (MediaEvent $event): void {
    AuditLog::create([
        'event' => $event->name(),
        'actor_id' => $event->actorId(),
        'subject_id' => $event->assetId(),
        'payload' => $event->payload(),
    ]);
});
```

Keep the log on the same database connection as the library, otherwise a failed insert cannot roll the change back. The example ships a ready listener, migration and a read-only Filament page ([`RecordMediaAudit`](examples/Articles/Listeners/RecordMediaAudit.php)).

![A read-only audit page built on the events: when, event name, actor, asset and payload](docs/images/audit-log.png)

## Licence expiry

An asset may have a `license_expires_at`. Expiry is **computed when read**, not stored as a flag, so it is never stale.

- The library page shows a "Licence governance" banner when any licence has lapsed or lapses within `expiry_warning_days` (default 30). It also says how many expired assets are **still used**. **Review expired** and **Review upcoming** filter the grid.
- Cards show "Licence expired", or the date it expires.
- The picker stops offering expired assets.
- Expiry does **not** stop an image being served. A page that already uses the asset keeps working. The library tells people; it does not take images down.

![The library filtered to expired licences: the banner counts one expired asset that is still used](docs/images/library-expiry.png)

The same numbers are available in code, for a scheduled reminder or a dashboard widget:

```php
use Banyan\FilamentMediaLibrary\Governance\MediaExpiryReport;

$summary = app(MediaExpiryReport::class)->summary();   // optional: summary($now, $warningDays)

$summary->expired;                 // licences that have lapsed
$summary->expiredReferenced;       // ...of which still used
$summary->expiringSoon;            // lapsing within the warning window
$summary->expiringSoonReferenced;  // ...of which still used
$summary->warningDays;
$summary->hasIssues();             // expired > 0 or expiringSoon > 0
```

## Configuration

Publish the config with `php artisan vendor:publish --tag=media-library-config`.

| Key | Default | Meaning |
| --- | --- | --- |
| `disk` | `public` (env `MEDIA_LIBRARY_DISK`) | Laravel disk for originals and renditions |
| `paths.originals` | `media` | Prefix for original files on that disk |
| `paths.renditions` | `media/renditions` | Prefix for cached renditions |
| `driver` | `gd` (env `MEDIA_LIBRARY_DRIVER`) | `gd` or `imagick` |
| `uploads.max_size` | `12288` | Largest upload, in kilobytes |
| `uploads.mime_types` | `image/jpeg`, `image/png`, `image/gif`, `image/webp`, `image/avif` | Accepted uploads |
| `renditions.max_dimension` | `4096` | Largest width or height a rendition may ask for |
| `renditions.quality` | `82` | Quality for JPEG, WebP and AVIF output |
| `expiry_warning_days` | `30` | Warn this long before a licence lapses (minimum 1) |
| `tables.assets` | `media_assets` | Table name |
| `tables.versions` | `media_versions` | Table name |
| `tables.tags` | `media_tags` | Table name |
| `tables.asset_tag` | `media_asset_tag` | Pivot table name |
| `tables.usages` | `media_usages` | Table name |
| `migrations` | `true` | Set `false` to skip the package's migrations |
| `abilities.view` | `media.view` | Gate ability for browsing |
| `abilities.manage` | `media.manage` | Gate ability for changing the library |
| `route.path` | `media/{uuid}/render` | Rendition route (must keep `{uuid}`) |
| `route.name` | `media-library.render` | Rendition route name |
| `navigation.register` | `true` | Add **Media library** to the sidebar |
| `navigation.group` | `null` | Navigation group: a string or a navigation-group enum |
| `navigation.sort` | `20` | Sidebar order |

Change table names before the first migration runs. Raise PHP's `upload_max_filesize` and `post_max_size`, and Livewire's temporary-upload limit in `config/livewire.php`, if you raise `uploads.max_size`.

## Existing tables and custom table names

Set `tables.*` to your own names. If those tables already exist, set `migrations` to `false` so the package does not try to create them. If you publish the migration (`--tag=media-library-migrations`) to edit it, set `migrations` to `false` so only your edited copy is loaded. The guide lists the columns the models expect.

## Look and feel, dark mode, languages

- **Colours** come from your panel's palette (`--gray-*`, `--primary-*`, `--warning-*`, `--success-*`), so the library follows your theme, including dark mode, with no work. To fine-tune it, set the `--ml-*` custom properties on `.bn-media`. They are listed at the top of `resources/css/media.css`.
- **Languages:** English, Traditional Chinese (`zh-Hant`) and Simplified Chinese (`zh-Hans`). Publish to change the wording:

  ```bash
  php artisan vendor:publish --tag=media-library-translations
  ```

  The files land in `lang/vendor/media-library/{locale}/media.php`. Keys are read as `media-library::media.*`.

![The library in dark mode](docs/images/dark.png)

## Exceptions

All extend `Banyan\FilamentMediaLibrary\Exceptions\MediaException` (a `RuntimeException`).

| Exception | When | Notes |
| --- | --- | --- |
| `AltTextRequired` | An upload or details change has empty alt text (after trimming) | Thrown by the repository, not only the form |
| `UnreadableImage` | The bytes are not an image this install can decode | Check `ext-gd` or `ext-imagick` and the driver |
| `AssetReferenced` | Deleting an asset that is still used | Has `$assetId` and `$usageCount`. The message names the count, never the filename |

## Testing

Package tests:

```bash
composer test       # Pest
composer analyse    # PHPStan level 9
```

The suite boots a bare Laravel app with Filament and this package only. Its table names carry a `lib_` prefix, so any query that ignores the table config fails. It covers the gate, the governance events, the picker, the pages, the signed route and the usage tracking, plus the worked example.

To test your own integration (a resource with a picker, usage recording, signed URLs, an audit listener), see [Testing your integration](docs/guide.md#10-testing-your-integration).

## Running the example

[`examples/Articles`](examples/Articles) is a complete, tested module built on the plugin: articles with a hero image and a gallery, a usage-recording trait, an editors gate, an audit listener with its own table and Filament page, and a public page that renders a srcset. Its tests are in [`tests/Feature/Examples/ArticlesTest.php`](tests/Feature/Examples/ArticlesTest.php). The [guide](docs/guide.md) explains it piece by piece.

To try it in your app, map its namespace in `composer.json` (`examples/` is left out of Composer downloads, so copy it from the repository):

```json
"autoload": { "psr-4": { "Banyan\\FilamentMediaLibrary\\Examples\\": "examples/" } }
```

Then add `ArticlesServiceProvider` to `bootstrap/providers.php`, add `ArticleResource` and `MediaAuditResource` to your panel's `->resources([...])`, list the editors' emails in `config/example-articles.php` (key `editors`), and run `php artisan migrate`.

## FAQ and troubleshooting

**An image URL answers 403.** The signature is invalid. Common causes: the URL was edited; `APP_KEY` changed since it was generated; the URL was generated for another host or scheme than the one it is requested on (check `APP_URL` and your trusted proxies). Generate the URL again with `RenditionService::url()`. Signatures do not expire.

**An image URL answers 404.** The asset (uuid) no longer exists, or its original file is missing from the disk.

**There is no Upload button.** The signed-in user cannot manage. `media.manage` is undefined (managing is closed by default) or it returns `false` for this user. Define it, or use `MediaLibraryGate::manageUsing()`. If you renamed the ability in config, define that name.

**The Media library menu item does not show.** Browsing is refused (`media.view` is defined and returns `false`), or `navigation.register` is `false`.

**The usage table says "Article #1", not the title.** That is the default resolver, which only knows the model class and key. Bind your own `UsageResolver` to show titles and links.

**An asset I use shows no usage and can be deleted.** The model never called `UsageRecorder::sync()`. The picker stores ids only.

**I deleted an article and the image is still "in use".** Call `UsageRecorder::forget($article)` when the model is deleted.

**Images 404 after I changed `MEDIA_LIBRARY_DISK`.** Each asset keeps using the disk it was uploaded to (originals, replacements and renditions), so keep the old disk defined in `config/filesystems.php`. Only new uploads go to the new disk.

**Do I need `storage:link`?** No. Nothing is served by a direct URL to the disk.

**Do I need a queue?** No. Renditions are made on the first request. A queued pipeline is a different feature with its own failure modes.

**Can I upload an SVG or a PDF?** No. The library accepts raster images that GD or Imagick can decode. Listing another type in `uploads.mime_types` does not make the decoder read it.

**Where can I read more?** The [usage guide](docs/guide.md).

## Licence

MIT. See [LICENSE](LICENSE).
