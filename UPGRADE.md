# Upgrading

## From 0.1 to 0.2

Version 0.2 has breaking changes. Work through the sections below in order. After you update, run these two commands:

```bash
php artisan filament:assets
php artisan migrate
```

### Renamed classes

Change the `use` statements and any class name written as a string, for example in a panel config or a Livewire test.

| 0.1 | 0.2 |
| --- | --- |
| `Banyan\FilamentMediaLibrary\Admin\MediaLibraryPage` | `Banyan\FilamentMediaLibrary\Pages\MediaLibraryPage` |
| `Banyan\FilamentMediaLibrary\Admin\MediaAssetPage` | `Banyan\FilamentMediaLibrary\Pages\MediaAssetPage` |
| `Banyan\FilamentMediaLibrary\Admin\Concerns\*` | `Banyan\FilamentMediaLibrary\Pages\Concerns\*` |
| `Banyan\FilamentMediaLibrary\Rendering\MediaThumbnailColumn` | `Banyan\FilamentMediaLibrary\Tables\MediaThumbnailColumn` |
| `Banyan\FilamentMediaLibrary\Rendering\ImagickPdfThumbnailer` | `Banyan\FilamentMediaLibrary\Rendering\Pdf\ImagickPdfThumbnailer` |
| `Banyan\FilamentMediaLibrary\Rendering\NullPdfThumbnailer` | `Banyan\FilamentMediaLibrary\Rendering\Pdf\NullPdfThumbnailer` |
| `Banyan\FilamentMediaLibrary\Rendering\PdfThumbnailService` | `Banyan\FilamentMediaLibrary\Rendering\Pdf\PdfThumbnailService` |
| `Banyan\FilamentMediaLibrary\Admin\Support\MediaForms` | `Banyan\FilamentMediaLibrary\Forms\MediaForms` |
| `Banyan\FilamentMediaLibrary\Admin\Support\MediaText` | `Banyan\FilamentMediaLibrary\Support\MediaText` |
| `Banyan\FilamentMediaLibrary\Admin\Support\FolderChoices` | `Banyan\FilamentMediaLibrary\Folders\FolderChoices` |
| `Banyan\FilamentMediaLibrary\Admin\Support\FolderNode` | `Banyan\FilamentMediaLibrary\Folders\FolderNode` |

The `Admin` directory is gone. The `Contracts\PdfThumbnailer` interface did not move.

If you published the views (`--tag=media-library-views`), also update the CSS classes in your copies. See the next section.

### CSS classes and tokens

Every `bn-media` class now starts with `ml-`.

| 0.1 | 0.2 |
| --- | --- |
| `bn-media` (the wrapper) | `ml-library` |
| `bn-media__*` | `ml-library__*` |
| `bn-media-picker__*` | `ml-picker__*` |
| `bn-media-expiry-heading`, `bn-media-focal-help`, `bn-media-search`, `bn-media-tag` | `ml-expiry-heading`, `ml-focal-help`, `ml-search`, `ml-tag` |

The stylesheet no longer reads any Banyan token (`--bn-*`). It only knows its own `--ml-*` tokens, with Filament's palette as the fallback. To restyle the library, set `--ml-*` on `.ml-library` in your own stylesheet. The defaults have no specificity, so your rule wins whatever order the stylesheets load in. If you used `--bn-*` tokens with the library outside Banyan CMS, map them yourself:

```css
.ml-library {
    --ml-text: var(--bn-text);
    --ml-surface: var(--bn-surface);
}
```

Banyan CMS does this for you in `banyancms/media`.

### Events

The events keep the same names (`media.asset.uploaded` and so on) and the same payloads, key for key. The `MediaEvent`, `MediaAssetEvent` and `MediaFolderEvent` interfaces are unchanged. Only the PHP classes changed.

- Every asset event now extends the abstract `Events\AssetEvent`. Every folder event extends the abstract `Events\FolderEvent`.
- The two base classes hold `$actor` and the id, so every event has a public `$assetId` or `$folderId`.
- Each event has a `NAME` constant, for example `AssetUploaded::NAME`. `name()` returns it.
- The `$id` property of `AssetMoved`, `AssetDeleted` and every folder event is now `$assetId` or `$folderId`. If you create these events yourself with a named argument, change `id:` to `assetId:` or `folderId:`.
- `AssetDeleted::$kind` is now a `MediaKind` (it was a string). The `kind` key in the payload is still the string value, such as `image`.
- The `AssetSubject` and `FolderSubject` traits are removed. The base classes do their job.
- The order of the constructor arguments did not change.

### The `$mimeType` argument is gone

`MediaGovernanceService::upload()` and `replace()`, and `MediaRepository::store()` and `replace()`, no longer take a `$mimeType`. The library always detected the type from the bytes and ignored the value. Remove the argument.

```php
// 0.1
$media->upload($bytes, 'campus.jpg', 'image/jpeg', 'The campus at dusk', (string) $user->id);
$media->replace($asset, $bytes, 'campus-2027.jpg', 'image/jpeg', (string) $user->id);

// 0.2
$media->upload($bytes, 'campus.jpg', 'The campus at dusk', (string) $user->id);
$media->replace($asset, $bytes, 'campus-2027.jpg', (string) $user->id);
```

If you pass the arguments by name, remove `mimeType:`. A call that still passes a MIME type shifts every later argument, so search your code for `->upload(`, `->replace(` and `->store(`.

### Route settings

The four routes now live together under `routes`. The `route` key and the `delivery.*_route` keys are removed.

| 0.1 | 0.2 |
| --- | --- |
| `route.path`, `route.name` | `routes.render.path`, `routes.render.name` |
| `delivery.file_route.path`, `.name` | `routes.file.path`, `routes.file.name` |
| `delivery.restricted_file_route.path`, `.name` | `routes.restricted_file.path`, `routes.restricted_file.name` |
| `delivery.restricted_render_route.path`, `.name` | `routes.restricted_render.path`, `routes.restricted_render.name` |

The defaults are the same. The other `delivery.*` keys (`temporary_url_minutes`, `redirect_to_storage`, `restricted_url_minutes` and `restricted_middleware`) did not move. If you published `config/media-library.php`, move your values by hand. An old key is ignored without any error, and the route falls back to its default.

### Migrations

The four 0.1 migrations are now one migration, `2026_10_21_000001_create_media_library_tables.php`. It creates the seven tables with every column from the old migrations.

- A new install needs nothing extra. Run `php artisan migrate`.
- If you already ran the 0.1 migrations, your tables are correct. Do not run anything. Laravel still lists the old migration names in its `migrations` table. This does no harm.
- If you published the migrations, delete your copies of the three removed files (`add_file_kinds_to_media_tables`, `add_captions_to_media_assets` and `create_media_folders`).
- If you set `migrations` to `false`, nothing changes for you.

The database schema is the same as 0.1 after all four migrations ran.
