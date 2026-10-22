# banyancms/filament-media-library

**[READ ONLY]** Subtree split of the `filament-media-library` package from [BanyanCMS/banyan](https://github.com/BanyanCMS/banyan).

A governed **media library for Filament v5**: images, documents, video, audio and archives, organised in folders that can be shared, members-only or password protected. Every image has alt text and every other file has a title. Replacing a file adds a version and keeps the old one. Crops follow a focal point that an editor sets. Licences expire, and the library warns before they do. An asset that is still used cannot be deleted. Every change is a Laravel event that you can write to your own audit log.

Editors manage files in one place, and pick them from any form with a `MediaPicker` field. Your site asks for an image by size and gets a signed, cached, focal-point-aware crop, and links to a PDF, a video or an audio file through one signed file route. Files are checked on upload by what they are, not what they are called.

![The media library: a licence warning, search and tag filters, a folder tree, folder tiles and a grid of assets. A padlock marks an asset that is still used, so it cannot be deleted](docs/images/library.png)

It is a standalone Filament plugin. It needs no other Banyan package. It was extracted from [Banyan CMS](https://github.com/BanyanCMS/banyan), whose media library runs on it. Licensed **MIT**.

> **Building a real feature?** Read the **[usage guide](docs/guide.md)**. It walks through a complete, tested example (articles with a hero image and a gallery): who may upload, putting images on a model, keeping "where is it used" correct, showing images on your site, an audit log, licence expiry, documents and video, folders and folder access, customising and testing.

> **Where to change it:** the source lives in the Banyan monorepo at `packages/filament-media-library`. This repository is its published split. Open issues and pull requests against the [monorepo](https://github.com/BanyanCMS/banyan).

---

## Contents

- [What you get](#what-you-get)
- [Inspiration and how it compares](#inspiration-and-how-it-compares)
- [Requirements](#requirements)
- [Installation](#installation)
- [Quick start: an image on an article in five steps](#quick-start-an-image-on-an-article-in-five-steps)
- [Who may do what](#who-may-do-what)
- [The library and the asset page](#the-library-and-the-asset-page)
- [File kinds and accepted types](#file-kinds-and-accepted-types)
- [Upload safety](#upload-safety)
- [MediaPicker: choosing a file in a form](#mediapicker-choosing-a-file-in-a-form)
- [Where an asset is used](#where-an-asset-is-used)
- [Showing an image](#showing-an-image)
- [Delivering files](#delivering-files)
- [Previews](#previews)
- [The file component](#the-file-component)
- [Folders](#folders)
- [Folder access](#folder-access)
- [Writing from code](#writing-from-code)
- [Data model and querying from your code](#data-model-and-querying-from-your-code)
- [Audit log: events](#audit-log-events)
- [Licence expiry](#licence-expiry)
- [Configuration](#configuration)
- [Existing tables and custom table names](#existing-tables-and-custom-table-names)
- [Look and feel, dark mode, languages](#look-and-feel-dark-mode-languages)
- [Overriding pages and views](#overriding-pages-and-views)
- [Exceptions](#exceptions)
- [Testing](#testing)
- [Running the example](#running-the-example)
- [FAQ and troubleshooting](#faq-and-troubleshooting)
- [Licence](#licence)

---

## What you get

- **A library page** (folder tree, grid, search, tag filter, licence filters) and **a page per asset** (preview, details, focal point, usage, version history), added to a panel with one line.
- **Five kinds of file.** Images, documents (PDF, Word, Excel, PowerPoint, OpenDocument, CSV, text), video (MP4, WebM), audio (MP3, M4A, WAV) and ZIP archives. The kind comes from the file's bytes and is never chosen by the uploader.
- **Upload safety.** The type is detected from the content, the extension must agree, Office files with macros and zip bombs are refused, and an `AssetUploading` event plus a `FileScanner` hook (a ClamAV scanner ships as an opt-in) can refuse anything else before a byte is written.
- **Previews.** A PDF shows in the browser's own viewer, video and audio play in the panel with captions and a transcript, and PDFs get first-page thumbnails when Imagick and Ghostscript are installed.
- **Folders and sub-folders**, with a tree, breadcrumbs, move, and delete rules that never lose a file. Each folder is **shared**, **members-only** or **password protected**, and access only ever narrows down the tree.
- **Alt text is required for images, and a title for everything else**, at three levels: the form, the repository (a seeder or an import cannot skip it) and the database.
- **Versions.** Replacing a file appends a version and keeps the old file. Nothing is overwritten.
- **Focal point.** An editor clicks or drags on the image. Every crop keeps that point in frame.
- **Licence expiry.** An asset can carry an expiry date. The library shows how many licences have lapsed or are about to, and how many of those are still used.
- **Usage tracking and delete protection.** An asset that is used somewhere cannot be deleted. The asset page lists where it is used.
- **`MediaPicker`**, a form field that chooses (and, for editors, uploads) an asset anywhere in your forms. It offers images by default; `->documents()`, `->videos()`, `->audio()` and `->kinds([...])` widen it, and `->folder()` scopes it.
- **One signed file route** for everything that is not a rendition, with `nosniff`, a stored content type, `Range` requests for seeking, and temporary URLs on S3-style disks. Files in restricted folders use short-lived, session-checked addresses instead.
- **`<x-media-library::file>`**, a Blade component that renders an asset the way its kind wants: a responsive image, a link with the title, format and size, or a player with captions.
- **Signed renditions.** `RenditionService::url()` returns a signed URL for any size. The image is made on first request and cached. Replacing the file or moving the focal point changes the URL.
- **`MediaThumbnailColumn`** for Filament tables.
- **Events for an audit log.** One for every change to an asset or a folder, written inside the change's own transaction. Payloads never hold alt text, titles, filenames, folder names or passwords.
- **Gate abilities** for access, closed by default for anything that changes the library, plus `media.manage-all` for the library administrator.
- **Any Laravel disk**, configurable table names, English, Traditional Chinese and Simplified Chinese.
- **No queue, no scheduler, no JavaScript build.** Renditions and PDF thumbnails are made on request. Expiry is computed when you read it.

![One asset: the focal point crosshair with two sample crops, details, where it is used and the version history](docs/images/asset.png)

## Inspiration and how it compares

### Where the ideas come from

This library was built for Banyan, a CMS aimed at government, public bodies and schools. Those organisations are moving away from WordPress and looking at Drupal or Umbraco as the alternative. The design borrows from those systems, and from image services:

- **Drupal Media and the Focal Point module.** A media item is a reusable entity, not a file attached to one record, and every crop keeps the point an editor chose.
- **Umbraco's media section.** One shared library for the whole site, with "where is this used?" answered before anything is deleted.
- **The WordPress media library.** The grid, the per-asset page and alt text as a first-class field. Here alt text is required, not optional.
- **Image CDNs such as imgix and Cloudinary.** Renditions are made on request from a URL, cached, and signed so the URL cannot be abused as a free resizing service.
- **Digital asset management (DAM) practice.** Licence expiry, version history instead of overwriting, and an audit trail of who changed what. These are the governance needs of public-sector content.
- **WordPress folder plugins (FileBird, Real Media Library) and TomatoPHP's media manager.** WordPress needs a plugin for folders, and large libraries are unusable without them. Folders here are built in, and some material can be restricted to a team or protected with a password.

The principle that runs through all of it: **a file is a governed asset**, not a file upload. It has an accessible description or title, a rights window, a history and a list of the pages that depend on it.

### How it differs from other Filament media plugins

All of these are good tools that solve different problems. This summary comes from each project's public page as of October 2026; check them for current details.

| | **This library** | [Media Library Pro](https://filamentphp.com/plugins/ralphjsmit-media-library-manager) (Ralph J. Smit) | [Crumbls Filament Media Library](https://github.com/Crumbls/filament-media-library) | [TomatoPHP Media Manager](https://github.com/tomatophp/filament-media-manager) | [Media Action](https://filamentphp.com/plugins/hugomyb-media-action) (hugomyb) |
| --- | --- | --- | --- | --- | --- |
| **What it is** | A governed media library: files, folders, picker, renditions, events | A media manager for the admin panel | A WordPress-style media library | A folder-based file manager | A preview action, not a library |
| **Licence** | MIT | Paid (listed at €99) | MIT | MIT | MIT |
| **Storage** | Its own tables (`media_assets`, versions, tags, usages, folders). No spatie/laravel-medialibrary | Built on spatie/laravel-medialibrary | spatie/laravel-medialibrary plus its own `media_library` and `mediables` tables | spatie/laravel-medialibrary plus folders | None; it plays a URL you pass in |
| **Organising** | Folders and sub-folders (shared, members-only or password protected), tags, search, licence filters | Folders, filters, sorters | Grid, search, type filter | Folders and sub-folders, shared or password-protected | n/a |
| **Alt text** | **Required** for images, with a required title for other files (database, service and form) | Not listed | Optional field | Not listed | n/a |
| **Cropping** | Focal point: every rendition crops around it automatically | Not listed | Manual crop, rotate and flip (CropperJS) | Responsive images; no crop tool listed | n/a |
| **Replacing a file** | Adds a version; old files kept; URLs change, so caches never show old pixels | Not listed | Not listed | Not listed | n/a |
| **"Where is it used"** | Usage tracking; deleting a used asset is refused | Not listed | Polymorphic attachments | Model trait | n/a |
| **Licence and rights expiry** | Warnings, "still used" counts, picker hides expired assets | Not listed | Not listed | Not listed | n/a |
| **Audit and integration** | Events inside the transaction (an audit failure rolls back); Banyan webhooks | Custom actions | Roadmap | Folders API | n/a |
| **Permissions** | Gate abilities (managing is closed until you define it), plus per-folder members and passwords | Permissions | Not listed | Per-user folder access | n/a |
| **Image engine** | Laravel's own `Illuminate\Image` | Spatie conversions | Spatie conversions | Spatie conversions | n/a |
| **File types** | Images, documents, video, audio, archives | Files | Files | Files | Video, audio, PDF and images (preview) |

**When to choose another plugin.** You already use spatie/laravel-medialibrary collections on your models. You need SVG, video transcoding or streaming (HLS), full-text search inside documents, or a password per file rather than per folder; none of those are in this library yet. You only need to preview a file in a modal; Media Action does that and composes with any library, this one included.

**When to choose this one.** Accessibility and rights are requirements, not nice-to-haves. The same file is reused on many pages. Replacing or deleting a file must be safe and traceable. Documents must be uploaded safely and some of them kept inside a team. You need crops that keep the subject in frame without an editor cropping every size by hand.

## Requirements

- PHP 8.3+, Laravel 13, Filament v5, Livewire v4.
- `ext-gd` (default) or `ext-imagick`. Image work uses Laravel's own image component (`Illuminate\Image`, the `Image` facade), which drives `intervention/image` v4. Set `media-library.driver` to `null` to use your app's `images.default`.
- `ext-fileinfo` (detects what an upload really is) and `ext-zip` (inspects zip, Office and OpenDocument files). Both are required by the package.
- Optional: `ext-imagick` with Ghostscript, for first-page thumbnails of PDFs ([Previews](#previews)).
- A database. Table names are configurable; see [Configuration](#configuration).

## Installation

```bash
composer require banyancms/filament-media-library
php artisan migrate
php artisan filament:assets
```

The service provider is discovered automatically. `filament:assets` publishes the library's stylesheet; run it again after updating the package. The migrations create seven tables (`media_assets`, `media_versions`, `media_tags`, `media_asset_tag`, `media_usages`, `media_folders`, `media_folder_members`). An existing install of an earlier release only needs `php artisan migrate`: three new migrations add the file-kind, caption and folder columns, and none of the old migrations changed. If you want other names, or you already have these tables, read [Configuration](#configuration) **before** you run `migrate`.

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

**Storage.** Files go to the disk named by `media-library.disk` (default `public`, env `MEDIA_LIBRARY_DISK`). Originals, renditions and every other file are always served through the signed routes, never by a URL straight to the disk. So the disk can be private and you do **not** need `php artisan storage:link`. Point the config at an S3 disk and nothing else changes. If you accept large files, raise PHP's `upload_max_filesize` and `post_max_size`, and Livewire's temporary-upload limit in `config/livewire.php`, to match `uploads.kinds.*.max_size`.

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

The alt text is the asset's own and is never empty. That is the whole feature working end to end. For a PDF, a video or an audio file instead of an image, see [Delivering files](#delivering-files) and [The file component](#the-file-component).

![The Articles list with a thumbnail column](docs/images/articles-table.png)

---

## Who may do what

Three Laravel Gate abilities. Their names are in `media-library.abilities`.

| Ability | Default name | Allows | When it is not defined |
| --- | --- | --- | --- |
| view | `media.view` | Open the library and asset pages | Any signed-in user |
| manage | `media.manage` | Upload, replace, edit details, refocus, retag, move, delete (including the picker's "Upload new") | **Nobody** |
| manage all | `media.manage-all` | The library administrator: sees and manages every folder, including members-only and password-protected ones, without being a member or unlocking, and may change any folder's access | **Nobody** |

Folders add a second layer on top of these abilities: [Folder access](#folder-access) decides who may open each folder, and a `manage` role inside a members-only folder is a grant of its own.

Managing is refused until you define it. An undefined permission must never default to open:

```php
Gate::define('media.manage', fn (User $user): bool => $user->is_editor);
```

If your permissions do not live in a Gate ability, give the library a fallback. It is asked **only while the matching ability is undefined**:

```php
use Banyan\FilamentMediaLibrary\Access\MediaLibraryGate;

MediaLibraryGate::manageUsing(fn (): bool => auth()->user()?->hasRole('editor') === true);
MediaLibraryGate::manageAllUsing(fn (): bool => auth()->user()?->hasRole('super-admin') === true);
```

All checks return `false` for a guest. Every write re-checks the gate on the server (page actions, the focal point save and the picker's upload all do), so a hidden button is not the only protection.

Two things to know:

- The library asks `Gate::allows()` first, so a `Gate::before()` grant counts (this is how permission packages such as spatie/laravel-permission and super-admin shortcuts work), even when `media.manage` was never `define()`d. Only when nothing grants it and the ability is undefined does `manageUsing()` (or `manageAllUsing()`) decide. Note that a blanket `Gate::before()` super-admin grant also grants `media.manage-all`.
- `MediaGovernanceService` and the other classes you call from code do **not** check the gate. Only the pages and the picker do. See [Writing from code](#writing-from-code).

A signed-in user who may not manage sees a read-only library:

![The library for a user without media.manage: no Upload button, and no delete or padlock icons on the cards](docs/images/non-editor.png)

## The library and the asset page

![The upload dialog: file, title or alt text, licence expiry and tags](docs/images/upload.png)

- **Upload files** (managers only) asks for the file, then the **alt text** (required for an image) or a **title** (required for every other kind: it is the link text people read), an optional licence expiry date and comma-separated tags. Tags are lower-cased on save. Opened inside a folder, the dialog files the upload there. One file at a time. The form turns away a type that is not on the list with a short message; the [upload guard](#upload-safety) behind it is the real check.
- The grid shows the folder tiles of the open folder first, then the newest **120** assets, with **Load more** for the next 120 and a "Showing 120 of N" count. The search box (filename, title or alt text), the tag filter and the licence filters narrow it. Inside a folder they search that folder and everything below it; **Search the whole library** widens them.
- A card shows a thumbnail for an image (and for a PDF, when [thumbnails are available](#previews)) or a kind icon, the kind, size and filename, a trash icon when the asset is unused and a padlock when it is used. Click a card to open the asset page. Tick cards to move several files at once ([Folders](#folders)).
- On the asset page, **Edit details** changes alt text (images) or title (everything else) and licence expiry. **More** holds Move to folder, Edit tags, Captions (video), Transcript (video and audio), Replace file and, for an asset nothing uses, Delete asset. The focal point is set on the image itself and exists for images only.
- The **Version history** lists every file the asset has had. It is a record: there is no restore or download button in this release. A replacement must be the same kind: replacing a PDF with an image is refused.

![A PDF's asset page: the browser's own viewer inline, with page count, kind, title, folder and details beside it](docs/images/pdf-preview.png)

## File kinds and accepted types

Every asset has a **kind**: `image`, `document`, `video`, `audio` or `archive` (the `MediaKind` enum). The kind is worked out from the file's bytes when it is uploaded and is never chosen by the uploader, because a kind the uploader could pick is a kind the uploader could lie about. It decides the validation, the preview and which text is required.

These are the defaults in `media-library.uploads.kinds`. A type that is not listed is refused with `UnsupportedFileType`.

| Kind | Accepted | Extensions | Default maximum |
| --- | --- | --- | --- |
| `image` | JPEG, PNG, GIF, WebP, AVIF | `jpg` `jpeg` `jpe` `png` `gif` `webp` `avif` | 12 MB (`12288` KB) |
| `document` | PDF; Word, Excel and PowerPoint (`docx` `xlsx` `pptx`, without macros); OpenDocument text, spreadsheet and presentation; CSV; plain text; WebVTT captions | `pdf` `docx` `xlsx` `pptx` `odt` `ods` `odp` `csv` `txt` `vtt` | 50 MB (`51200` KB) |
| `video` | MP4 (H.264/AAC), WebM | `mp4` `m4v` `webm` | 500 MB (`512000` KB) |
| `audio` | MP3, M4A (AAC), WAV | `mp3` `m4a` `wav` | 100 MB (`102400` KB) |
| `archive` | ZIP | `zip` | 100 MB (`102400` KB) |

Never accepted, whatever the extension: HTML, XML, SVG, JavaScript, executables, and the macro-enabled `docm`, `xlsm` and `pptm`. An Office file that contains `vbaProject.bin` is refused even when it is named `.docx`. SVG needs sanitising first and is not in this release.

Change the lists and limits in the config, per kind:

```php
'uploads' => [
    'kinds' => [
        'document' => ['mime_types' => ['application/pdf'], 'max_size' => 20480], // PDFs only, 20 MB
        // ... the other kinds as in the default config
    ],
],
```

`max_size` is in kilobytes. The older flat `uploads.max_size` and `uploads.mime_types` keys still work and mean the image kind when `kinds.image` is absent. Listing a type under `image` does not make the decoder read it: adding `image/svg+xml` does not make SVG work. A WebVTT file (`text/vtt`) is a document that must start with `WEBVTT`; it exists to be a video's captions.

An asset stores `kind`, `title`, `page_count` (for a PDF, when it can be read), `duration_seconds` (video and audio, left null because reading it needs ffmpeg or getID3, which this package does not depend on; fill it yourself if you know it) and the detected `mime_type`. `$asset->kind` is a `MediaKind`; `$asset->isImage()`, `$asset->formatLabel()` ("PDF", "DOCX") and `$asset->displayName()` (the title, or the filename) are the helpers.

## Upload safety

Whatever the surface (the library, the picker's upload button, `MediaGovernanceService::upload()` from a job), the same checks run in `MediaRepository::store()` and `replace()`, **before anything is written**. A refused file leaves no row and no file on the disk. In this order:

1. **Detect the type from the bytes** with `finfo`. The file's name and the browser's claim are ignored. Zip-based formats (Office, OpenDocument) are told apart from plain zips by what is inside them; CSV versus plain text is decided by the extension, since the content cannot say.
2. **The extension must agree** with the detected type, or the file is refused with `ExtensionMismatch`. This stops `cv.docx` that is really HTML, and `report.pdf.exe`.
3. **The type must be on the allow-list** (`UnsupportedFileType`), and a replacement must be the **same kind** as the asset (`KindMismatch`).
4. **The kind's size limit** (`FileTooLarge`).
5. **Kind-specific checks:**
   - an image must decode (`UnreadableImage`);
   - a PDF must start with `%PDF-`;
   - for a zip, an Office file or an OpenDocument file, the entries are listed and the file is refused if there are more than `uploads.archive.max_entries` (default 5000) or if the entries expand to more than 1 GB (`UploadRejected`: a zip-bomb guard);
   - an Office file with macros is refused (`UnsupportedFileType`).
6. **The `AssetUploading` event**, then the **scanner**, if you set one (below).
7. **The required text.** An image needs alt text (`AltTextRequired`), everything else a title (`TitleRequired`).

The form's own file field also lists the accepted types and size, so a wrong file is usually turned away with a short message before it reaches the guard. Code that calls the services directly gets the guard and nothing else, which is why the guard is the real check.

### Refusing a file yourself: `AssetUploading`

Dispatched once the built-in checks pass and before the scanner. It is a plain event, not a `MediaEvent`, and it is not an audit entry: it carries the temporary file's path.

```php
use Banyan\FilamentMediaLibrary\Enums\MediaKind;
use Banyan\FilamentMediaLibrary\Events\AssetUploading;
use Banyan\FilamentMediaLibrary\Exceptions\UploadRejected;
use Illuminate\Support\Facades\Event;

Event::listen(function (AssetUploading $upload): void {
    // $upload->path, ->filename, ->mimeType (detected), ->kind (MediaKind), ->sizeBytes
    if ($upload->kind === MediaKind::Document && str_contains($upload->filename, 'draft')) {
        throw UploadRejected::because('drafts are not published here.');
    }
});
```

A listener that throws refuses the upload. Do not put the filename in the message: the exception text is shown to the uploader and may be logged.

### Scanning: `FileScanner` and ClamAV

Name a class in `media-library.uploads.scanner` that implements `Banyan\FilamentMediaLibrary\Contracts\FileScanner`. It runs last, on a temporary copy of the bytes. `null` (the default) scans nothing.

```php
use Banyan\FilamentMediaLibrary\Contracts\FileScanner;
use Banyan\FilamentMediaLibrary\Scanning\ScanResult;

final class MyScanner implements FileScanner
{
    public function scan(string $path): ScanResult
    {
        return $this->engine->isInfected($path)
            ? ScanResult::infected('Malware detected.')   // shown to the uploader; never the filename
            : ScanResult::clean();
    }
}
```

An infected result throws `UploadRejected` with that reason. A scanner that cannot reach its engine should **throw**, not return clean: an unscanned file must not read as a safe one. The class is resolved from the container, so it can take dependencies, and a class that does not implement `FileScanner` throws a `LogicException`.

A ClamAV scanner ships as an opt-in example. It speaks clamd's `INSTREAM` command over a socket, is not a dependency and is off until you name it:

```php
// config/media-library.php
'uploads' => [
    'scanner' => \Banyan\FilamentMediaLibrary\Scanning\ClamAvScanner::class,
    'clamav' => [
        'socket' => 'unix:///var/run/clamav/clamd.ctl',   // or tcp://127.0.0.1:3310; this is the default
        'timeout' => 10,                                    // seconds to connect; the default
    ],
    // ... kinds, archive
],
```

When clamd is unreachable the scanner throws, so uploads fail until it is back: scanning that fails open means nothing during exactly the outage an attacker would pick. clamd refuses a stream longer than its `StreamMaxLength` (25 MB by default) with an error, which also fails the upload; raise it in `clamd.conf` to at least your largest `max_size`. Laravel's config merge is shallow, so an `uploads` array you publish replaces the default one; anything you leave out falls back to the built-in defaults.

![An upload refused: a Word file that contains a macro, with the notification explaining why](docs/images/upload-refused.png)

## MediaPicker: choosing a file in a form

```php
use Banyan\FilamentMediaLibrary\Enums\MediaKind;
use Banyan\FilamentMediaLibrary\Forms\MediaPicker;

MediaPicker::make('hero_id');                       // one asset: stores an id. Images only, the default
MediaPicker::make('gallery')->multiple();           // many assets: stores a list of ids
MediaPicker::make('hero_id')->thumbnails();         // a 48px thumbnail beside each option
MediaPicker::make('hero_id')->uploadable(false);    // no "Upload new" button on this field

MediaPicker::make('report_id')->documents();        // PDFs, Office files, CSV, text
MediaPicker::make('tour_id')->videos();             // MP4 and WebM
MediaPicker::make('message_id')->audio();           // MP3, M4A, WAV
MediaPicker::make('bundle_id')->archives();         // ZIP
MediaPicker::make('any_id')->anyKind();             // every kind
MediaPicker::make('media_id')->kinds([MediaKind::Image, MediaKind::Video]);

MediaPicker::make('report_id')->documents()->folder($reports);   // only this folder and below, and uploads go there
MediaPicker::make('note_id')->documents()->allowRestricted();    // intranet: offer restricted folders too
```

`MediaPicker` extends Filament's `Select`, so `->label()`, `->required()`, `->helperText()`, `->hint()`, `->live()`, `->maxItems()` and the other `Select` methods work as usual.

![The picker open: the chosen image on top, a search box and matching assets with thumbnails](docs/images/picker.png)

- **Kinds.** A field offers images unless you say otherwise, so a field written before file kinds existed behaves as before, and a "hero image" field is never handed a PDF. `->kinds([])` falls back to images, never to everything. The kinds apply to the options, to the search and to the inline upload, and a saved value is checked against them too.
- **Options** are the newest 50 selectable assets of those kinds. Typing searches filename, title and alt text and returns up to 50 matches. An image option reads "filename — alt text"; any other kind reads "title — Kind". When folders are on, the folder path is added ("in Publications / Annual reports") so two files with the same name are told apart.
- **Expired assets are not offered.** Choosing is where a new usage starts, and a lapsed licence is what expiry exists to stop. If a record already holds an asset that has since expired, it still shows, labelled "Licence expired".
- **`->folder($folder)`** takes a `MediaFolder` or its uuid. It offers only that folder and the folders below it, and an inline upload is filed there. A uuid that matches no folder offers nothing, never the whole library. It is ignored when `folders.enabled` is `false`.
- **Folder access applies.** The picker only ever offers assets the signed-in user may browse, so a file in a members-only folder they are not in is not in the options, the search results or the Livewire payload. A saved value is checked again on the server, so a hand-made request cannot select a file the picker would not have offered.
- **Restricted folders are hidden by default**, even from people who can open them, because a restricted file cannot be shown on a public page ([Folder access](#files-in-restricted-folders-and-public-pages)). `->allowRestricted()` offers them, for intranet sites and back-office fields.
- **Upload new** (the button at the end of the field) opens the same dialog as the library, requires alt text for an image or a title for anything else, goes through the same upload guard and audited path, and selects the new asset. It accepts only the field's kinds. It is visible only to users who may manage the folder the upload would go to, and the gate is checked again when the dialog is submitted. Wait for the file to finish uploading before you submit.
- **Thumbnails** are off by default. They turn every option into HTML: an image gets a 48px crop, a PDF its first page when [thumbnails are available](#previews), anything else the kind's icon. The plain list is the cheapest thing for a screen reader and a slow connection.
- **Accessibility.** The picker is a standard searchable `Select`, so it has Filament's keyboard and screen-reader behaviour. The library does not ship a bespoke widget to keep conformant.

![The documents picker: only documents are offered, searched for "annual", with the folder each one lives in](docs/images/picker-documents.png)

![The picker's upload dialog while a file is uploading](docs/images/picker-upload.png)

![After the upload: the new image is selected, and a "Asset uploaded." notice shows](docs/images/picker-uploaded.png)

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

## Delivering files

Images are served as before: `$asset->url($w, $h, $fit)` returns a signed rendition ([Showing an image](#showing-an-image)). Every other file, and the original of an image, is served by one signed **file route**:

```php
$asset->fileUrl();       // inline for an image, PDF, video and audio; an attachment for everything else
$asset->downloadUrl();   // always an attachment
```

```blade
<a href="{{ $asset->downloadUrl() }}">Download {{ $asset->displayName() }}</a>
<video controls src="{{ $asset->fileUrl() }}"></video>
```

The route is `GET /media/{uuid}/file` (named `media-library.file`; change both in `delivery.file_route`, keeping `{uuid}`). It has the `signed` middleware and no session, so it is cacheable. The URL carries the current version and the `download` flag inside the signature, so a link cannot be flipped between inline and attachment by editing it, and a replaced file is a new address that no cache serves stale. An unsigned or altered request gets `403`; an unknown asset or a missing file gets `404`.

What the response always carries:

- `Content-Type` is the type detected when the file was uploaded and stored with the asset, never one guessed from the request.
- `X-Content-Type-Options: nosniff`, so a browser cannot decide a stored file is something else.
- `Content-Disposition` is `inline` for images, PDFs, video and audio, and `attachment` for every other kind (or when you ask for `downloadUrl()`). The suggested filename is the asset's title plus the stored extension ("Annual report 2025.pdf"), or the stored filename when there is no title. Slashes, quotes, control characters, `%`, `:`, `*`, `?`, `<`, `>` and `|` are replaced, it is cut at 150 characters, and an RFC 6266 `filename*` carries the non-ASCII name.
- `Cache-Control: public, max-age=31536000, immutable`.

**Ranges and seeking.** Video and audio need `Range` requests to seek, and what happens depends on the disk:

| Disk | What the route does |
| --- | --- |
| Local (`public`, `local`) | Streams the file with Symfony's `BinaryFileResponse`, which answers `Range` requests. Seeking works |
| One with temporary URLs (S3, R2) | Answers `302` to a short-lived provider URL (`delivery.temporary_url_minutes`, default 5) with the content type and disposition set on it. The provider does the ranging |
| Anything else | Streams the whole file. There is no `Range` support, so video plays but does not seek |

A members-only or password-protected file is served from different, session-checked routes with short-lived addresses ([Folder access](#files-in-restricted-folders-and-public-pages)). `fileUrl()` and `downloadUrl()` pick the right one for you, and throw `AccessDenied` for a restricted file the signed-in user may not browse, so an empty `src` can never be printed by accident. Check `$asset->isDeliverable()` first when you cannot be sure.

## Previews

The asset page shows each kind the way a browser can do natively. There is no conversion, no queue and no extra JavaScript, and the previews use classes rather than inline styles.

| Kind | What the asset page shows |
| --- | --- |
| Image | The focal point editor and two crop previews |
| PDF | An inline `<iframe>` of the file route, which is the browser's own viewer, with the page count, an **Open in new tab** link and **Download** |
| Video | An HTML5 `<video controls preload="metadata">`, with the captions attached to it as a `<track>`, and **Download** |
| Audio | An HTML5 `<audio controls>`, the transcript under it, and **Download** |
| Word, Excel, PowerPoint, OpenDocument, CSV, text, ZIP | A kind icon, the size, the details and **Download** |

![Audio: the player and a transcript, with the title, folder, duration and details beside it](docs/images/audio.png)

**Captions and transcripts.** On a video's asset page, **More, Captions** attaches a WebVTT file (`.vtt`) that is already in the library as a document. On a video or audio file, **More, Transcript** stores the words spoken as plain text, shown in a collapsible block under the player. In code:

```php
$media->setCaptions($video, $vttAsset, (string) $user->id);   // null removes them; video only
$media->setTranscript($audio, $text, (string) $user->id);     // null removes it; video and audio only
```

Both throw `CompanionNotAllowed` for the wrong kind, and `setCaptions()` also if the file is not WebVTT. The events say whether a transcript exists, never what it says. `$video->captionsUrl()` returns the captions file's address, or `null` when there are none or the signed-in user may not have them.

![A video's asset page: the player with a time bar, captions attached, and the duration](docs/images/video.png)

**Page count and duration.** The page count of a PDF is read from the file when it is uploaded, by counting page objects, so a PDF that keeps its pages inside compressed object streams shows none. The duration of video and audio is **not** read, because that needs ffmpeg or getID3 and the package depends on neither. `duration_seconds` stays null unless your app sets it. There is no video poster frame either.

**PDF thumbnails.** In the grid and the picker a PDF shows its first page when the `PdfThumbnailer` contract has an implementation that works on your server, and a kind icon when it does not. The default implementation uses `ext-imagick`, which needs Ghostscript behind it:

```bash
apt install ghostscript php-imagick   # then restart PHP-FPM
```

If Imagick is not loaded, or its `PDF` format is not readable, nothing breaks: the card shows the icon. Many Linux distributions also disable the PDF coder in ImageMagick's `policy.xml`; allow reading PDF there. Thumbnails are made on first request at 160, 320 or 640 pixels wide, cached next to the renditions (the version is in the path, so a replaced PDF gets a new one), and served by the rendition route. Any failure on a malformed PDF costs the card an icon, never a 500. To use another renderer (a service, `pdftoppm`), bind your own:

```php
use Banyan\FilamentMediaLibrary\Contracts\PdfThumbnailer;

$this->app->singleton(PdfThumbnailer::class, MyPdfThumbnailer::class);
```

It has two methods: `available(): bool`, and `thumbnail(string $pdfBinary, int $width): ?string` which returns PNG bytes or `null`.

## The file component

`<x-media-library::file>` renders an asset the way its kind wants, so a template does not need a branch per kind:

```blade
<x-media-library::file :asset="$article->report" />
<x-media-library::file :asset="$hero" :width="1200" :height="630" sizes="(min-width: 52rem) 52rem, 100vw" class="rounded" />
```

| Kind | What it prints |
| --- | --- |
| Image | `<img>` with a `srcset` of 600, 1200 and 1800 pixel renditions, `sizes`, the alt text, `loading="lazy"` and `decoding="async"` |
| PDF | `<a>` to `fileUrl()` (opens in the browser) with the link text "Annual report 2025 (PDF, 2.4 MB)" |
| Other documents and archives | The same link, to `downloadUrl()` |
| Video | `<video controls preload="metadata">`, a captions `<track>` when there is one, and a download link as the fallback inside it |
| Audio | `<audio controls preload="metadata">` with a download link as the fallback |

Video and audio with a transcript also get a `<details>` block holding it. The link text is title, format and size, which is the WCAG-friendly way to name a document link. Extra attributes (`class`, `id`, `data-*`) go onto the element, and `width`, `height` and `sizes` apply to what they make sense for.

A file in a members-only or password-protected folder is not for the public. When the visitor may not have it, the component prints a lock icon with the label "Restricted file", and nothing else: no URL, title or filename. A signed-in user who may browse the folder gets the file.

## Folders

Folders organise a large library, and are on by default. Set `media-library.folders.enabled` to `false` for a flat library: all folder UI is hidden, and every asset reads as being at the root.

![The Publications folder open: the tree with its sub-folders expanded, breadcrumbs and folder tiles](docs/images/folders.png)

- **Tree and navigation.** The library page has a collapsible, keyboard-navigable tree (`role="tree"`) and breadcrumbs. The current folder is also the folder that **Upload files** and **New folder** act on.
- **Folder actions.** **New folder** creates one inside the open folder. The **Folder** menu holds **Access…**, **Rename folder**, **Move folder** and **Delete folder**.
- **Moving files.** Tick cards in the grid and use **Move to folder…**, or open an asset and use **More, Move to folder**. Moving an asset keeps its id, its uuid and its usages. Files that the user may not change are left where they are, with a notice.
- **Deleting a folder** is allowed only when it is empty. Otherwise the action offers **Move its contents to the parent folder first**, which lifts the sub-folders and files up one level and then deletes the empty folder, all or nothing. It never cascades and never deletes a file.
- **Search** inside a folder covers that folder and everything below it, and **Search the whole library** widens it. The same goes for the tag and licence filters.

The rules, enforced in `MediaFolderService`, whatever surface asks:

- A name is 1 to 120 characters, without a slash or a control character, and unique among its siblings, compared case-insensitively.
- A folder cannot be moved into its own subtree (`FolderCycle`), and nesting is capped by `folders.max_depth` (default 8, `FolderTooDeep`).
- Renaming or moving to a name a sibling already has is refused (`FolderNameTaken`).
- Each folder keeps a materialised `path` (`/12/45/`) and `depth`, so a whole subtree is one query. Only the service writes them; never set `parent_id` by hand.

### The service

All folder writes go through `MediaFolderService`, one transaction each, with events dispatched before the commit, like `MediaGovernanceService`. Like it, **it does not check the gate**: the pages and the picker check access before they call it, and code that calls it on a user's behalf must do the same ([Folder access](#folder-access)).

```php
use Banyan\FilamentMediaLibrary\Folders\MediaFolderService;

$folders = app(MediaFolderService::class);

$reports = $folders->create(null, 'Annual reports', (string) $user->id);          // top level
$y2025 = $folders->create($reports, '2025', (string) $user->id);                    // sub-folder
$folders->rename($y2025, '2025-26', (string) $user->id);
$folders->move($y2025, null, (string) $user->id);                                   // to the top level
$folders->moveAssets([$asset, $other], $reports, (string) $user->id);               // returns how many moved
$folders->delete($y2025, (string) $user->id);                                       // must be empty
$folders->deleteMovingContentsToParent($reports, (string) $user->id);               // lift, then delete

$media->upload($bytes, 'report.pdf', 'application/pdf', '', (string) $user->id, null, 'Annual report 2025', $reports);
```

| Method | Returns | Throws |
| --- | --- | --- |
| `create(?MediaFolder $parent, string $name, string $actorId, ?int $siteId = null)` | `MediaFolder` | `InvalidFolderName`, `FolderTooDeep`, `FolderNameTaken` |
| `rename(MediaFolder $folder, string $name, string $actorId)` | `MediaFolder` | `InvalidFolderName`, `FolderNameTaken` |
| `move(MediaFolder $folder, ?MediaFolder $to, string $actorId)` | `MediaFolder` | `FolderCycle`, `FolderTooDeep`, `FolderNameTaken` |
| `delete(MediaFolder $folder, string $actorId)` | `void` | `FolderNotEmpty` |
| `deleteMovingContentsToParent(MediaFolder $folder, string $actorId)` | `void` | `FolderNameTaken` |
| `moveAssets(array $assets, ?MediaFolder $to, string $actorId)` | `int` | none (assets already there are skipped) |
| `setAccess(...)`, `addMember(...)`, `removeMember(...)` | | See [Folder access](#folder-access) |

`$siteId` tags a top-level folder with a site, for hosts with several sites (see the Banyan adapter's README); a standalone install never uses it. To tell the page which site the editor is working on, call `MediaLibraryPage::currentSiteUsing(fn (): ?int => ...)` once; that site's top-level folders then list first.

### Events

Each change dispatches an event: `FolderCreated`, `FolderRenamed`, `FolderMoved`, `FolderDeleted` and, for assets, `AssetMoved`. They are `MediaFolderEvent`s (or a `MediaAssetEvent`) and carry ids and counts only, never a folder name. The full table is under [Audit log: events](#audit-log-events).

### From your own code

```php
use Banyan\FilamentMediaLibrary\Models\MediaAsset;
use Banyan\FilamentMediaLibrary\Models\MediaFolder;

$folder = MediaFolder::findByUuid($uuid);

$folder->breadcrumbs();          // ancestors and the folder itself, outermost first
$folder->ancestors();            // parent, grandparent ... outermost first
$folder->children;               // direct sub-folders
$folder->descendantsQuery();     // everything below it, one query
$folder->contains($other);       // is $other this folder, or below it?

MediaAsset::query()->inFolder($folder)->get();                 // directly in it
MediaAsset::query()->inFolder($folder, true)->get();           // in it or below it
MediaAsset::query()->inFolder(null)->get();                    // unfiled: the library root
MediaAsset::query()->ofKind(MediaKind::Document)->latest()->get();
MediaAsset::query()->visibleTo($user)->search('annual')->get(); // only what $user may see
```

## Folder access

Every folder has an **access mode**. Choose it with **Folder, Access…** on the library page:

| Mode | Who can browse and pick | Who can upload, edit and delete inside |
| --- | --- | --- |
| **Same as the folder above** (`inherit`, the default) | As the parent; at the top level it means shared | As the parent |
| **Shared** | Anyone with `media.view` | Anyone with `media.manage` |
| **Members only** | The folder's owner, its members and holders of `media.manage-all` | The owner, members with the `manage` role, and `media.manage-all`. `media.manage` is not needed: the role is the grant |
| **Password protected** | Anyone with `media.view` who has **unlocked** it | Anyone with `media.manage` who has unlocked it |

A member has one of two roles: `view` (browse and pick) or `manage` (also upload, edit and delete). The owner is the person who created the folder.

![The access dialog of a members-only folder: the mode, and a member with the "Can browse and pick" role](docs/images/folder-access.png)

### Access only narrows down the tree

A folder's *effective* access is that of the nearest ancestor (or itself) that is not `inherit`, which is what `$folder->effectiveAccess()` reports. What is **enforced** is stricter: **a user must pass every folder on the path from the top.** A `shared` sub-folder of a members-only folder therefore stays members-only. A child can never re-publish its parent's contents, and the parent's name never appears in a child's breadcrumb or picker label for someone who may not open the parent. The access dialog says so: "A sub-folder can be stricter than the folder above it, never more open." `$folder->isRestricted()` is true if any folder on the path is members-only or password protected.

### Who is exempt

- **The folder's owner and `media.manage-all`** open a members-only folder without being members.
- **Neither needs to unlock a password folder.** The owner chose the password, and the administrator can reset it, so typing it would protect nothing. Everyone else, `media.manage` holders included, must unlock.
- **Only the owner or `media.manage-all` may change a folder's access, password or members**, and only while they could manage it anyway.
- **The password does not bypass the Gate.** A user still needs `media.view`.

### Password folders: unlock and throttle

Set a password of at least 8 characters in the access dialog. It is stored with `Hash::make()`, never shown again and never put in an event, log or message. To keep the current one, leave the field empty; setting a new one **locks the folder again for everyone**, and so does leaving the mode and coming back.

A person who has not unlocked the folder sees it in the tree with a lock and a **Locked** tile, but no count, no files and no sub-folders, and a panel with an **Unlock** button.

![A password folder waiting to be unlocked: the password prompt](docs/images/folder-unlock.png)

- **The unlock lasts `folders.unlock_minutes`** (default 30) and covers the folder's whole subtree. It is kept server-side in the session as an expiry per folder, never in a cookie the client can forge, and is tied to the current password.
- **Five wrong attempts per user per folder in 15 minutes are throttled** (Laravel's `RateLimiter`). After that even the right password is refused until the window passes, and the dialog says how many minutes to wait.
- **Failures are audited.** Each wrong attempt, and each throttled one, dispatches `FolderUnlockFailed` with the number of attempts, and never the password. A successful unlock is not an event, to avoid noise.

### What a person without access sees

A restricted folder is **absent** for a user who may not open it: not greyed out, not "access denied", but not in the payload at all. It is not in the tree, the folder tiles, the breadcrumbs, search results, the picker options or the asset page, nor in the Livewire payload and HTML that carry them (the package's tests search the page's HTML and its whole Livewire snapshot for the folder's name, its files and their ids). A count never includes a file or folder they cannot see. A password folder is the one exception: it is *listed* (with a lock) so a person who knows the password can find it, but its contents are not.

![A member who is not in the Council papers folder: the folder is not in the tree or the tiles, and the Press embargo folder shows as Locked](docs/images/non-member.png)

An asset's page (`/admin/media/{uuid}`) is a 404 for someone who may not see it, whether it exists or not, except that a file in a password folder the person could unlock sends them to that folder's unlock form. Every refusal in the delivery routes is a 404, never a 403, so nothing confirms that a file exists.

### Files in restricted folders, and public pages

Ordinary file and rendition URLs are signed and permanent, so anyone holding one can open it. That cannot be true of a member's file. For an asset whose path holds a members-only or password folder:

- `fileUrl()`, `downloadUrl()` and `url()` return a **temporary** signed URL (`delivery.restricted_url_minutes`, default 10), issued only to a user who may browse the folder. For anyone else they throw `AccessDenied`.
- The URL points at **different routes**, `restricted-file` and `restricted-render`, which run inside your `web` middleware (`delivery.restricted_middleware`) because they need the visitor's session. On every request they check, again, that the signed-in user may browse the folder. A leaked URL fails for a logged-out visitor, and for the same person once their membership is removed or the folder is locked again.
- The public routes answer **404** for a restricted file, so an old permanent address dies at the moment the file moves into a restricted folder.
- Restricted answers are `Cache-Control: private, no-store`.

**The consequence: a restricted file cannot be used on a public page.** The picker hides restricted folders by default for that reason, and `<x-media-library::file>` prints a lock instead of a restricted file. To use restricted files on an intranet, where every visitor signs in, opt in with `->allowRestricted()` on the field. When an editor moves files that are still used on pages into a restricted folder, or restricts a folder that holds such files, the dialog warns how many are in use and asks for a confirmation (and the server checks the confirmation again); the move is allowed, but those pages lose the file.

If you build a public page from assets yourself, select only what is safe to show:

```php
MediaAsset::query()->unrestricted()->whereIn('id', $ids)->get();   // no restricted folder on its path
```

### From your own code

```php
use Banyan\FilamentMediaLibrary\Access\FolderAccess;
use Banyan\FilamentMediaLibrary\Enums\FolderAccessMode;

$access = app(FolderAccess::class);

$access->canBrowse($folderOrAsset, $user);   // may this user see inside? null means the library root
$access->canManage($folderOrAsset, $user);   // may they upload, edit and delete there?
$access->canAdminister($folder, $user);      // may they change its access, password and members?
$access->unlock($folder, $password);         // bool; throttled; remembered in the session
$access->isUnlocked($folder);
$access->isRestricted($folderOrAsset);

$folders->setAccess($folder, FolderAccessMode::Members, null, $actorId);
$folders->addMember($folder, $user->id, 'view', $actorId);       // or 'manage'; changes the role if already a member
$folders->removeMember($folder, $user->id, $actorId);
$folders->setAccess($press, FolderAccessMode::Password, 'a-long-password', $actorId);
```

`FolderAccess` is the one place a folder decision is made: the grid, the tree, the asset page, the picker, the file routes and `MediaAsset::visibleTo()` all ask it. Pass the user explicitly in jobs and console commands; without one it asks the signed-in user, and nobody signed in passes nothing. It is registered as a scoped binding that caches the folder table for one request, and the folder and member models clear that cache on every write.

`MediaFolderService::setAccess()` throws `InvalidFolderAccess` for a short password, a password on a folder that is not password protected, or a password mode without a password. Members are kept when you switch modes and ignored unless the mode is `members`. Member ids are your user ids (an integer), and the member list in the dialog shows the user attribute named in `folders.member_label` (default `name`, falling back to `email`).

## Writing from code

All writes go through `MediaGovernanceService`. Each runs in a transaction and announces itself with an event:

```php
use Banyan\FilamentMediaLibrary\Governance\MediaGovernanceService;

$media = app(MediaGovernanceService::class);

$asset = $media->upload($bytes, 'campus.jpg', 'image/jpeg', 'The campus at dusk', (string) $user->id);
$report = $media->upload($pdf, 'ar-2025.pdf', 'application/pdf', '', (string) $user->id, null, 'Annual report 2025', $folder);
$media->updateDetails($asset, 'The campus at dusk, from the north gate', null, (string) $user->id);
$media->setFocalPoint($asset, 0.7, 0.4, (string) $user->id);
$media->syncTags($asset, ['campus', 'events'], (string) $user->id);
$media->setCaptions($video, $vtt, (string) $user->id);
$media->setTranscript($audio, 'Good morning, and welcome.', (string) $user->id);
$media->replace($asset, $newBytes, 'campus-2027.jpg', 'image/jpeg', (string) $user->id);
$media->delete($asset, (string) $user->id);
```

| Method | Returns | Throws |
| --- | --- | --- |
| `upload(string $binary, string $filename, string $mimeType, string $altText, string $actorId, ?DateTimeInterface $licenseExpiresAt = null, ?string $title = null, ?MediaFolder $folder = null)` | `MediaAsset` | `AltTextRequired`, `TitleRequired`, `UnreadableImage`, and the [upload guard](#upload-safety) exceptions |
| `replace(MediaAsset $asset, string $binary, string $filename, string $mimeType, string $actorId)` | `MediaVersion` | `KindMismatch`, `UnreadableImage`, and the upload guard exceptions |
| `updateDetails(MediaAsset $asset, string $altText, ?DateTimeInterface $licenseExpiresAt, string $actorId, ?string $title = null)` | `void` | `AltTextRequired`, `TitleRequired` |
| `setCaptions(MediaAsset $video, ?MediaAsset $captions, string $actorId)` | `void` | `CompanionNotAllowed` |
| `setTranscript(MediaAsset $asset, ?string $transcript, string $actorId)` | `void` | `CompanionNotAllowed` |
| `setFocalPoint(MediaAsset $asset, float $x, float $y, string $actorId)` | `void` | none (clamped to 0 to 1) |
| `syncTags(MediaAsset $asset, array $names, string $actorId)` | `void` | none |
| `delete(MediaAsset $asset, string $actorId)` | `void` | `AssetReferenced` |

`$mimeType` is what the client claimed and is ignored: the type is detected from the bytes and that is what is stored. `$altText` is required for an image and may be empty for any other kind, which needs `$title` instead. `$folder` files the new asset in a folder (null is the library root). `$actorId` is your user's id as a string. It goes into the event. `updateDetails()` sets the licence expiry to exactly what you pass, so `null` clears it, and `$title` is left alone when null. `uploaded_by` on the asset is set only when it is numeric. A replacement keeps the asset's folder, title and captions.

These methods do not check the gate or folder access: if the call is made on behalf of a user, check `MediaLibraryGate::allowsManage()` and `app(FolderAccess::class)->canManage($folder)` yourself.

## Data model and querying from your code

Read the tables freely from any controller, service, job or report. **Write only through `MediaGovernanceService`** ([Writing from code](#writing-from-code)): it keeps the asset/version invariant, removes files on rollback and dispatches the events your audit log and webhooks depend on.

### Tables

The names below are the defaults. Each one is configurable in `media-library.tables.*`; Banyan uses `cms_media_*`. In raw queries, read the name from `MediaConfig::table('assets')` and the others, never hard-code it.

```mermaid
erDiagram
    media_assets ||--o{ media_versions : "has versions"
    media_assets ||--o| media_versions : "current_version_id"
    media_assets ||--o{ media_asset_tag : ""
    media_tags ||--o{ media_asset_tag : ""
    media_assets ||--o{ media_usages : "used by"
    media_folders ||--o{ media_assets : "folder_id"
    media_folders ||--o{ media_folders : "parent_id"
    media_folders ||--o{ media_folder_members : "members"
    media_assets ||--o| media_assets : "captions_asset_id"
```

**`media_assets`**: one row per file (an image, document, video, audio file or archive), the thing you reference from your own tables.

| Column | Type | Meaning |
| --- | --- | --- |
| `id` | bigint | Primary key. Store this in your own column (`hero_id`) |
| `uuid` | uuid, unique | Public identifier, used in URLs. Never expose `id` in a URL |
| `disk`, `path` | string | Where the **current** file lives. Each asset keeps the disk it was uploaded to |
| `filename` | string | Original filename of the current file. It can be personal data, so do not log it |
| `mime_type` | string | The type detected from the bytes, for example `image/jpeg` or `application/pdf` |
| `kind` | string(16), indexed | `image`, `document`, `video`, `audio` or `archive`. Derived at upload; never user-set. Existing rows read `image` |
| `title` | string(255), nullable | The human name, used as link text. Required for every kind except `image` |
| `size_bytes`, `width`, `height` | int | Of the current file. `width` and `height` are null for a non-image |
| `page_count` | int, nullable | A PDF's pages, when they could be counted. Informational |
| `duration_seconds` | int, nullable | Video and audio. Not read by the library; your app may set it. Informational |
| `captions_asset_id` | bigint, nullable | A video's WebVTT captions: another asset. Cleared if that asset is deleted |
| `transcript` | text, nullable | The words spoken in a video or audio file |
| `folder_id` | bigint, nullable, indexed | The folder it is filed in; null is the library root. Restrict on delete |
| `alt_text` | string, nullable | Required (and never empty) for an image; usually null for any other kind |
| `focal_x`, `focal_y` | decimal 0–1 | The point every crop keeps (0.5, 0.5 is the centre) |
| `license_expires_at` | timestamp, nullable | Null means no expiry |
| `current_version_id` | bigint, nullable | The version row of the current file |
| `uploaded_by` | bigint, nullable | Your user id, when it was numeric |
| `created_at`, `updated_at` | timestamp | |

**`media_versions`**: every file an asset has had; a replacement adds a row and the old file is kept.

| Column | Meaning |
| --- | --- |
| `id`, `media_asset_id` | Key, and the asset it belongs to (cascade on delete) |
| `disk`, `path`, `checksum` (sha256), `size_bytes`, `width`, `height` | The file |
| `kind` | The kind of that file, so a replacement can never change an asset's kind |
| `uploaded_by`, `created_at` | Who uploaded it and when (no `updated_at`) |

**`media_tags`** (`id`, `name` unique, lower-cased) and **`media_asset_tag`** (`media_asset_id`, `media_tag_id`): flat, editor-authored tags.

**`media_folders`**: the folder tree. Write it only through `MediaFolderService`.

| Column | Meaning |
| --- | --- |
| `id`, `uuid` | Key, and the public identifier used in URLs |
| `parent_id` | The parent folder; null is a top-level folder. Restrict on delete |
| `name` | 1 to 120 characters; unique per parent (`parent_id`, `name`), and case-insensitively in the service |
| `path`, `depth` | The ids from the top down, own id included (`/12/45/`), and the nesting level. They answer ancestor and subtree queries without recursion |
| `access` | `inherit` (the default), `shared`, `members` or `password` |
| `password_hash` | A `Hash::make()` hash, only when `access` is `password`. Hidden from serialisation; never returned, logged or put in an event |
| `owner_id`, `created_by` | Your user id of the creator. The owner may change the folder's access |
| `site_id` | Nullable. Tags a top-level folder with a site, for multi-site hosts |
| `created_at`, `updated_at` | |

**`media_folder_members`**: who may open a members-only folder.

| Column | Meaning |
| --- | --- |
| `media_folder_id` | The folder (cascade on delete) |
| `user_id` | Your user id |
| `role` | `view` (browse and pick) or `manage` (also upload, edit and delete). Primary key is (`media_folder_id`, `user_id`) |

**`media_usages`**: what the default resolver counts as "used". It is written by `UsageRecorder`.

| Column | Meaning |
| --- | --- |
| `media_asset_id` | The asset |
| `usable_type`, `usable_id` | Your model (`getMorphClass()` and key, the key stored as a string) |
| `field` | Which field holds it, for example `hero_id` |
| `locale` | Nullable, for per-locale fields |

If you bound your own `UsageResolver`, your usages live wherever your resolver reads them. Always ask the resolver (below), not this table.

### Models

All are in `Banyan\FilamentMediaLibrary\Models` and read the configured table names.

| Model | Relations | Helpers |
| --- | --- | --- |
| `MediaAsset` | `versions()` (newest first), `currentVersion()`, `tags()`, `folder()`, `captions()` | `isExpired()`, `isImage()`, `isRestricted()`, `isDeliverable()`, `url($w, $h, $fit)`, `fileUrl()`, `downloadUrl()`, `captionsUrl()`, `displayName()`, `formatLabel()`, `humanSize()`, `dimensions()`, `MediaAsset::findByUuid($uuid)`, `MediaAsset::formatBytes($bytes)` |
| `MediaVersion` | `asset()` | `shortChecksum()`, `humanSize()` |
| `MediaTag` | `assets()` | `MediaTag::normalise($name)` |
| `MediaFolder` | `parent()`, `children()`, `assets()`, `members()` | `effectiveAccess()`, `accessMode()`, `isRestricted()`, `breadcrumbs()`, `ancestors()`, `ancestorIds()`, `descendantsQuery()`, `subtreeQuery()`, `contains($other)`, `MediaFolder::findByUuid($uuid)`, `MediaFolder::pathLabels($folders)` |
| `MediaFolderMember` | | A row of `media_folder_members`; write it with `addMember()` and `removeMember()` |

Query scopes on `MediaAsset`:

| Scope | Selects |
| --- | --- |
| `selectable()` | Licence not expired, which is what the picker offers |
| `expired()` | Licence lapsed |
| `expiringWithin(int $days)` | Lapses within the next `$days` days |
| `search(string $term)` | Filename, title or alt text contains the term |
| `tagged(string\|array $names)` | Carries any of these tags |
| `used()` | Used somewhere, as the bound `UsageResolver` counts it |
| `ofKind(MediaKind\|list<MediaKind> $kinds)` | Of these kinds |
| `inFolder(?MediaFolder $folder, bool $recursive = false)` | Directly in the folder, or with `$recursive` in it or below it. `null` is the root: only unfiled assets, or with `$recursive` the whole library |
| `visibleTo(?Authenticatable $user = null)` | Only what this user may see: the root (when they may browse it) and the folders they may open. A password folder's assets are absent until it is unlocked. For admin surfaces, not public pages |
| `unrestricted()` | Only assets with no members-only or password folder on their path: what a public page may use |

`MediaFolder` has the scopes `visibleTo()` (folders a user may see in a tree) and `manageableBy()` (folders they may upload to).

### Helper services

| Class | Use it for |
| --- | --- |
| `Rendering\RenditionService` | `url($asset, $w, $h, 'crop'\|'contain')` gives a signed, cache-safe image URL; `$asset->url(...)` is the shortcut |
| `Contracts\UsageResolver` | `usages($id)`, `count($id)`, `counts([$ids])` (one query for a list), `whereUsed($query, 'column')` |
| `Usage\UsageRecorder` | `sync($model, 'field', [$ids])` and `forget($model)`, to keep usage correct |
| `Governance\MediaExpiryReport` | `summary()` gives the expired and expiring counts, and how many of them are still used |
| `Access\MediaLibraryGate` | `allowsBrowse()`, `allowsManage()`, `allowsManageAll()` and `actorId()` before acting on a user's behalf |
| `Access\FolderAccess` | `canBrowse()`, `canManage()`, `canAdminister()`, `unlock()`, `isUnlocked()`, `isRestricted()`: the one place a folder decision is made ([Folder access](#folder-access)) |
| `Folders\MediaFolderService` | Every folder write: `create`, `rename`, `move`, `delete`, `moveAssets`, `setAccess`, `addMember`, `removeMember` ([Folders](#folders)) |
| `Delivery\FileDelivery` | `url($asset, $download)`, `disposition($asset)`, `filename($asset)`: what the file route's addresses and headers are made of |
| `Rendering\PdfThumbnailService` | `url($asset, $width)` gives a signed first-page thumbnail URL, or `null` when there is none |
| `Contracts\FileScanner`, `Contracts\PdfThumbnailer` | Bind or name your own ([Upload safety](#upload-safety), [Previews](#previews)) |
| `Support\MediaConfig` | `table('assets')` and typed config reads |

### Recipes

```php
use Banyan\FilamentMediaLibrary\Contracts\UsageResolver;
use Banyan\FilamentMediaLibrary\Enums\MediaKind;
use Banyan\FilamentMediaLibrary\Models\MediaAsset;
use Banyan\FilamentMediaLibrary\Support\MediaConfig;

// An article's hero image for an API response.
$hero = MediaAsset::query()->find($article->hero_id);
$data = $hero === null ? null : [
    'uuid' => $hero->uuid,
    'alt' => $hero->alt_text,
    'width' => $hero->width,
    'height' => $hero->height,
    'url' => $hero->url(1200, 630, 'crop'),
    'thumb' => $hero->url(320, 240, 'crop'),
];

// A list of articles with their heroes, without N+1 queries.
$heroes = MediaAsset::query()->whereIn('id', $articles->pluck('hero_id')->filter())->get()->keyBy('id');

// A gallery stored as a JSON array of ids, in the editor's order.
$gallery = MediaAsset::query()->whereIn('id', $article->gallery ?? [])->get()
    ->sortBy(fn (MediaAsset $a) => array_search($a->id, $article->gallery, true))->values();

// Licences to chase this month: expiring and still on a page.
$toRenew = MediaAsset::query()->expiringWithin(30)->used()->orderBy('license_expires_at')->get();

// Campus photos an editor may still pick.
$campus = MediaAsset::query()->selectable()->tagged('campus')->latest()->paginate(24);

// Every PDF in a folder and its sub-folders that this user may open, newest first.
$reports = MediaAsset::query()->visibleTo($user)->ofKind(MediaKind::Document)->where('mime_type', 'application/pdf')
    ->inFolder($folder, true)->latest()->get();

// A document list for a public page: nothing from a restricted folder.
$downloads = MediaAsset::query()->unrestricted()->ofKind(MediaKind::Document)->whereIn('id', $ids)->get();

// Where one asset is used, and usage counts for a whole list in one query.
$usages = app(UsageResolver::class)->usages($asset->id);   // list<AssetUsage>: label, type, field, locale, url
$counts = app(UsageResolver::class)->counts($ids);         // [asset id => count]

// A join from your own table, with the configured table name.
$rows = DB::table('articles as a')
    ->join(MediaConfig::table('assets').' as m', 'm.id', '=', 'a.hero_id')
    ->where('m.mime_type', 'image/png')
    ->get(['a.id', 'a.title', 'm.uuid', 'm.alt_text']);
```

**Your own columns.** Store the asset id (`unsignedBigInteger('hero_id')->nullable()`, or a JSON list for a gallery). You may add a foreign key to `media_assets.id` with `nullOnDelete()`. The library refuses to delete an asset that is still used, so the key mainly protects you from bulk imports.

**Do not** update `media_assets`, `media_versions` or `media_folders` with `DB::table()->update()` or `$asset->save()`. That skips alt-text and title validation, the upload guard, the version history, rendition cache keys, the folder `path` and `depth` columns and the events. Use the services.

## Audit log: events

Every change dispatches an event that implements `Banyan\FilamentMediaLibrary\Events\MediaEvent`. It has `name()`, `subjectType()`, `subjectId()`, `actorId()` and `payload()`. An event about one asset also implements `MediaAssetEvent` (with `assetId()`), and one about one folder implements `MediaFolderEvent` (with `folderId()`). `subjectType()` is `media_asset` or `media_folder`.

| Event class | `name()` | Payload keys |
| --- | --- | --- |
| `AssetUploaded` | `media.asset.uploaded` | `asset_id`, `uuid`, `kind`, `folder_id`, `mime_type`, `size_bytes`, `width`, `height` |
| `AssetReplaced` | `media.asset.replaced` | `asset_id`, `uuid`, `kind`, `folder_id`, `version_id`, `superseded_version_id`, `size_bytes` |
| `AssetUpdated` | `media.asset.updated` | `asset_id`, `uuid`, `kind`, `folder_id`, `alt_text_changed` (bool), `title_changed` (bool), `license_expires_at` (ISO 8601 or null) |
| `FocalPointChanged` | `media.asset.refocused` | `asset_id`, `uuid`, `kind`, `folder_id`, `focal_x`, `focal_y` |
| `AssetTagged` | `media.asset.tagged` | `asset_id`, `uuid`, `kind`, `folder_id`, `tag_count` |
| `AssetMoved` | `media.asset.moved` | `asset_id`, `from_folder_id`, `to_folder_id` |
| `CaptionsChanged` | `media.asset.captions_changed` | `asset_id`, `uuid`, `kind`, `folder_id`, `captions_asset_id` (null when removed) |
| `TranscriptChanged` | `media.asset.transcript_changed` | `asset_id`, `uuid`, `kind`, `folder_id`, `has_transcript` (bool) |
| `AssetDeleted` | `media.asset.deleted` | `asset_id`, `uuid`, `kind`, `folder_id`, `usage_count` (the count at the moment of deletion) |
| `FolderCreated` | `media.folder.created` | `folder_id`, `uuid`, `parent_id`, `depth` |
| `FolderRenamed` | `media.folder.renamed` | `folder_id`, `uuid` |
| `FolderMoved` | `media.folder.moved` | `folder_id`, `from_parent_id`, `to_parent_id` |
| `FolderDeleted` | `media.folder.deleted` | `folder_id`, `uuid` |
| `FolderAccessChanged` | `media.folder.access_changed` | `folder_id`, `from`, `to` (modes), `members_count` |
| `FolderMembersChanged` | `media.folder.members_changed` | `folder_id`, `user_id`, `change` (`added`, `role_changed` or `removed`), `role` (null when removed), `members_count` |
| `FolderUnlockFailed` | `media.folder.unlock_failed` | `folder_id`, `attempts` |

Events are dispatched **inside the write's database transaction**. If a listener throws, the write is rolled back, so an audit log never describes a change that did not happen. Payloads carry ids, kinds, sizes, modes and flags. They never carry alt text, a title, a filename, a folder name or a password, which can name a person. A successful unlock is not an event; a failed one is.

`AssetUploading` (see [Upload safety](#upload-safety)) is a hook that runs before a write, not an audit event.

```php
use Banyan\FilamentMediaLibrary\Events\MediaEvent;
use Illuminate\Support\Facades\Event;

Event::listen(MediaEvent::class, function (MediaEvent $event): void {
    AuditLog::create([
        'event' => $event->name(),
        'actor_id' => $event->actorId(),
        'subject_type' => $event->subjectType(),   // 'media_asset' or 'media_folder'
        'subject_id' => $event->subjectId(),
        'payload' => $event->payload(),
    ]);
});
```

Keep the log on the same database connection as the library, otherwise a failed insert cannot roll the change back. The example ships a ready listener, migration and a read-only Filament page ([`RecordMediaAudit`](examples/Articles/Listeners/RecordMediaAudit.php)); its table is keyed by asset, so it records the asset events and leaves the folder events to you.

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
| `uploads.kinds.image.mime_types` | `image/jpeg`, `image/png`, `image/gif`, `image/webp`, `image/avif` | Accepted image types |
| `uploads.kinds.image.max_size` | `12288` | Largest image, in kilobytes |
| `uploads.kinds.document.mime_types` | `application/pdf`, the `docx`, `xlsx` and `pptx` types, the `odt`, `ods` and `odp` types, `text/csv`, `text/plain`, `text/vtt` | Accepted document types |
| `uploads.kinds.document.max_size` | `51200` | Largest document, in kilobytes |
| `uploads.kinds.video.mime_types` | `video/mp4`, `video/webm` | Accepted video types |
| `uploads.kinds.video.max_size` | `512000` | Largest video, in kilobytes |
| `uploads.kinds.audio.mime_types` | `audio/mpeg`, `audio/mp4`, `audio/wav` | Accepted audio types |
| `uploads.kinds.audio.max_size` | `102400` | Largest audio file, in kilobytes |
| `uploads.kinds.archive.mime_types` | `application/zip` | Accepted archive types |
| `uploads.kinds.archive.max_size` | `102400` | Largest archive, in kilobytes |
| `uploads.archive.max_entries` | `5000` | A zip, Office or OpenDocument file with more entries than this is refused. One that expands to over 1 GB is refused too (fixed) |
| `uploads.scanner` | `null` | A class implementing `FileScanner`, run on every upload. `null` scans nothing |
| `uploads.clamav.socket` | `unix:///var/run/clamav/clamd.ctl` | The clamd socket, used by `ClamAvScanner` only. Not in the published file; add it when you use the scanner |
| `uploads.clamav.timeout` | `10` | Seconds `ClamAvScanner` waits to connect. Likewise not in the published file |
| `uploads.max_size`, `uploads.mime_types` | none | The flat keys of earlier releases. They still mean the image kind when `uploads.kinds.image` is absent |
| `renditions.max_dimension` | `4096` | Largest width or height a rendition may ask for |
| `renditions.quality` | `82` | Quality for JPEG, WebP and AVIF output |
| `expiry_warning_days` | `30` | Warn this long before a licence lapses (minimum 1) |
| `tables.assets` | `media_assets` | Table name |
| `tables.versions` | `media_versions` | Table name |
| `tables.tags` | `media_tags` | Table name |
| `tables.asset_tag` | `media_asset_tag` | Pivot table name |
| `tables.usages` | `media_usages` | Table name |
| `tables.folders` | `media_folders` | Table name |
| `tables.folder_members` | `media_folder_members` | Table name |
| `migrations` | `true` | Set `false` to skip the package's migrations |
| `abilities.view` | `media.view` | Gate ability for browsing |
| `abilities.manage` | `media.manage` | Gate ability for changing the library |
| `abilities.manage_all` | `media.manage-all` | Gate ability for the library administrator: every folder, without membership or unlocking |
| `folders.enabled` | `true` | `false` hides all folder UI and reads every asset as being at the root |
| `folders.max_depth` | `8` | How deep folders may nest |
| `folders.unlock_minutes` | `30` | How long an unlocked password folder stays open in a session |
| `folders.member_label` | `name` | The user attribute shown when choosing a folder's members; falls back to `email` |
| `route.path` | `media/{uuid}/render` | Rendition route (must keep `{uuid}`) |
| `route.name` | `media-library.render` | Rendition route name |
| `delivery.file_route.path` | `media/{uuid}/file` | The signed file route (must keep `{uuid}`) |
| `delivery.file_route.name` | `media-library.file` | File route name |
| `delivery.temporary_url_minutes` | `5` | How long the temporary URL a remote disk (S3, R2) is redirected to stays valid |
| `delivery.restricted_url_minutes` | `10` | How long the address of a file in a restricted folder works |
| `delivery.restricted_middleware` | `['web']` | Middleware of the restricted routes; they need the visitor's session |
| `delivery.restricted_file_route.path` | `media/{uuid}/restricted-file` | Route for restricted files |
| `delivery.restricted_file_route.name` | `media-library.restricted-file` | Name of that route |
| `delivery.restricted_render_route.path` | `media/{uuid}/restricted-render` | Route for renditions and thumbnails of restricted files |
| `delivery.restricted_render_route.name` | `media-library.restricted-render` | Name of that route |
| `navigation.register` | `true` | Add **Media library** to the sidebar |
| `navigation.group` | `null` | Navigation group: a string or a navigation-group enum |
| `navigation.sort` | `20` | Sidebar order |

Change table names before the first migration runs. Raise PHP's `upload_max_filesize` and `post_max_size`, and Livewire's temporary-upload limit in `config/livewire.php`, if you raise a `max_size`: the defaults allow a 500 MB video, which PHP's defaults do not. Laravel's config merge is shallow, so a nested array you publish (such as `uploads` or `delivery`) replaces the default one wholesale; copy the whole block from the package's config before you edit it. Where a key is missing the built-in default above still applies.

## Existing tables and custom table names

Set `tables.*` to your own names (seven now, with `folders` and `folder_members`). If those tables already exist, set `migrations` to `false` so the package does not try to create them; they then need the columns listed under [Data model](#data-model-and-querying-from-your-code). If you publish the migration (`--tag=media-library-migrations`) to edit it, set `migrations` to `false` so only your edited copy is loaded. The guide lists the columns the models expect.

## Look and feel, dark mode, languages

- **Colours** come from your panel's palette (`--gray-*`, `--primary-*`, `--warning-*`, `--success-*`), so the library follows your theme, including dark mode, with no work. To fine-tune it, set the `--ml-*` custom properties on `.bn-media`. They are listed at the top of `resources/css/media.css`.
- **Languages:** English, Traditional Chinese (`zh-Hant`) and Simplified Chinese (`zh-Hans`). Publish to change the wording:

  ```bash
  php artisan vendor:publish --tag=media-library-translations
  ```

  The files land in `lang/vendor/media-library/{locale}/media.php`. Keys are read as `media-library::media.*`.

![The library in dark mode](docs/images/dark.png)

## Overriding pages and views

Swap either page for a subclass of your own, to change a query, add a header action or use your own view:

```php
use Banyan\FilamentMediaLibrary\Admin\MediaLibraryPage;

final class MediaLibrary extends MediaLibraryPage
{
    protected string $view = 'filament.pages.media-library';
}

// In your panel provider:
MediaLibraryPlugin::make()
    ->libraryPage(\App\Filament\Pages\MediaLibrary::class)
    ->assetPage(\App\Filament\Pages\MediaAsset::class);
```

The class must extend `MediaLibraryPage` or `MediaAssetPage`. The library and asset pages link to each other through the plugin, so the links go to your pages.

To change only the markup, publish the views. A view in `resources/views/vendor/media-library/` wins over the package's own:

```bash
php artisan vendor:publish --tag=media-library-views
```

Services are bound in the container, so you can rebind them too. Bind your own `UsageResolver`, for example (see [Where an asset is used](#where-an-asset-is-used)).

## Exceptions

All extend `Banyan\FilamentMediaLibrary\Exceptions\MediaException` (a `RuntimeException`). Messages never contain a filename, a folder name or a password.

**Uploads and files**

| Exception | When | Notes |
| --- | --- | --- |
| `AltTextRequired` | An image upload or details change has empty alt text (after trimming) | Thrown by the repository, not only the form |
| `TitleRequired` | A file that is not an image has no title | Likewise |
| `UnreadableImage` | The bytes are not an image this install can decode | Check `ext-gd` or `ext-imagick` and the driver |
| `UnsupportedFileType` | The detected type is not on the allow-list, a PDF does not start with `%PDF-`, or an Office file contains macros | The message names the detected type, or says macros are not accepted |
| `ExtensionMismatch` | The extension does not agree with what the bytes are | `cv.docx` that is really HTML |
| `FileTooLarge` | The file is over its kind's `max_size` | |
| `KindMismatch` | A replacement is not the same kind as the asset | A PDF replaced with an image |
| `UploadRejected` | A zip has too many entries or expands over 1 GB, an `AssetUploading` listener refused, or the scanner reported a threat | `UploadRejected::because($reason)`; the reason is shown to the uploader |
| `CompanionNotAllowed` | Captions on something that is not a video, a transcript on something that is not video or audio, or a captions file that is not WebVTT | |
| `AssetReferenced` | Deleting an asset that is still used | Has `$assetId` and `$usageCount`. The message names the count, never the filename |

**Folders and access**

| Exception | When | Notes |
| --- | --- | --- |
| `InvalidFolderName` | The name is empty, over 120 characters, or holds a slash or control character | |
| `FolderNameTaken` | A sibling already has this name (case-insensitive) | Also thrown when lifting contents out of a deleted folder would clash |
| `FolderCycle` | A folder is moved into its own subtree | |
| `FolderTooDeep` | Nesting would pass `folders.max_depth` | |
| `FolderNotEmpty` | Deleting a folder that holds files or sub-folders | Use `deleteMovingContentsToParent()` to lift them first |
| `InvalidFolderAccess` | A password under 8 characters, a password on a folder that is not password protected, a password folder without a password, a role other than `view` or `manage`, or a member that is not a user id | |
| `AccessDenied` | An address helper (`fileUrl()`, `downloadUrl()`, `url()`) is asked for a restricted file the signed-in user may not browse | Check `isDeliverable()` first to avoid it |

## Testing

Package tests:

```bash
composer test       # Pest
composer analyse    # PHPStan level 9
```

The suite boots a bare Laravel app with Filament and this package only. Its table names carry a `lib_` prefix, so any query that ignores the table config fails. It covers the gate, the governance events, the picker (including its kinds and folders), the pages, the signed routes, the upload guard, the scanner, the file delivery and previews, folders and folder access (including that a restricted folder is absent from the HTML and the Livewire payload of someone without access) and the usage tracking, plus the worked example.

To test your own integration (a resource with a picker, usage recording, signed URLs, an audit listener), see [Testing your integration](docs/guide.md#14-testing-your-integration).

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

**Can I upload a PDF? An SVG?** A PDF, yes: see [File kinds and accepted types](#file-kinds-and-accepted-types). An SVG, no. It can carry script and needs sanitising first, so it is not in this release. Listing `image/svg+xml` under `uploads.kinds.image` does not make it work: the image decoder cannot read it.

**An upload is refused with "Office files that contain macros are not accepted", "does not match its content" or "not accepted".** The upload guard decided from the bytes, not from the name. A `.docx` that holds a macro project, a `.pdf` that is really HTML and a type that is not on the list are refused, and nothing is written. See [Upload safety](#upload-safety). Convert the file, or add the type to `uploads.kinds` if you are sure you want it.

**A large upload fails before it reaches the library.** PHP's `upload_max_filesize` and `post_max_size`, and Livewire's temporary-upload limit (`config/livewire.php`), are lower than the kind's `max_size`. The defaults allow a 500 MB video, which PHP's defaults do not. Raise all three.

**PDF thumbnails show an icon, not the first page.** First-page thumbnails need `ext-imagick` with Ghostscript behind it. Install both (`apt install ghostscript php-imagick`) and restart PHP. If they are installed and it still fails, your ImageMagick `policy.xml` probably forbids the PDF coder; allow reading PDF there. Or bind your own `PdfThumbnailer`. See [Previews](#previews). The icon is the designed fallback, not an error.

**A video will not seek, or plays only after it has downloaded.** Seeking needs `Range` requests. They work on a local disk and, through a temporary URL, on S3-style disks. A disk without temporary URLs is streamed whole, with no ranges. A proxy or CDN that strips `Range`, or an MP4 whose index sits at the end of the file (encode it with `ffmpeg -movflags +faststart`), has the same symptom. See [Delivering files](#delivering-files).

**A video uploads but does not play.** The library accepts MP4 and WebM containers by what they are, but a browser plays only the codecs it knows. Use H.264 video with AAC audio in MP4. There is no transcoding in this release.

**A restricted image is broken on a public page.** By design. A file in a members-only or password-protected folder has a short-lived address that needs the visitor's session, so it cannot appear on a page that anonymous people see, and the old permanent address stops working the moment the file is moved in. Put public images in an open folder. On an intranet where everyone signs in, opt in with `MediaPicker::...->allowRestricted()`. See [Files in restricted folders, and public pages](#files-in-restricted-folders-and-public-pages).

**A folder, or a file, is missing for someone.** If it is members-only, it is absent for anyone who is not the owner, a member or a `media.manage-all` holder, and so is everything below it (access only narrows down the tree). That is the point: it is not hidden, it is not there. Add the person under **Folder, Access…**. A password folder is listed with a lock until they unlock it.

**I forgot a folder's password.** The folder's owner, or anyone with `media.manage-all`, opens **Folder, Access…** and sets a new one. It cannot be read back, since only a hash is stored, and setting a new one locks the folder again for everyone.

**"Too many wrong attempts".** Five wrong passwords per user per folder in 15 minutes are throttled, even if the next one is right. Wait for the time the message gives. The attempts are recorded as `FolderUnlockFailed` events.

**A member can see the folder but cannot upload to it.** Their role is `view`. Give them `manage` under **Access…**. In a members-only folder the role is the grant: they do not also need `media.manage`.

**"This folder is not empty".** A folder is deleted only when it holds nothing. Tick **Move its contents to the parent folder first** in the delete dialog to lift everything up one level; no file is ever deleted by deleting a folder.

**Where can I read more?** The [usage guide](docs/guide.md).

## Licence

MIT. See [LICENSE](LICENSE).
