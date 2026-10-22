<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Models;

use Banyan\FilamentMediaLibrary\Access\FolderAccess;
use Banyan\FilamentMediaLibrary\Contracts\UsageResolver;
use Banyan\FilamentMediaLibrary\Delivery\FileDelivery;
use Banyan\FilamentMediaLibrary\Enums\MediaKind;
use Banyan\FilamentMediaLibrary\Rendering\RenditionService;
use Banyan\FilamentMediaLibrary\Support\MediaConfig;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A managed file: an image, or (SPEC-0103) a document, video, audio file or archive. Flat, not tree-scoped: an asset is reused across sites and
 * across every page that uses it, so it belongs to no one record.
 *
 * @property int $id
 * @property string $uuid
 * @property string $disk
 * @property string $path
 * @property string $filename
 * @property string $mime_type
 * @property int $size_bytes
 * @property int|null $width
 * @property int|null $height
 * @property MediaKind $kind
 * @property string|null $title
 * @property string|null $alt_text
 * @property int|null $duration_seconds
 * @property int|null $page_count
 * @property int|null $captions_asset_id
 * @property string|null $transcript
 * @property float $focal_x
 * @property float $focal_y
 * @property CarbonInterface|null $license_expires_at
 * @property int|null $current_version_id
 * @property int|null $uploaded_by
 * @property int|null $folder_id
 */
final class MediaAsset extends Model
{
    public function getTable(): string
    {
        return MediaConfig::table('assets');
    }

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'focal_x' => 'float',
            'focal_y' => 'float',
            'size_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'kind' => MediaKind::class,
            'duration_seconds' => 'integer',
            'page_count' => 'integer',
            'license_expires_at' => 'datetime',
        ];
    }

    /** @return HasMany<MediaVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(MediaVersion::class, 'media_asset_id')->orderByDesc('id');
    }

    /** @return BelongsTo<MediaVersion, $this> */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(MediaVersion::class, 'current_version_id');
    }

    /**
     * The WebVTT file attached as this video's captions.
     *
     * @return BelongsTo<MediaAsset, $this>
     */
    public function captions(): BelongsTo
    {
        return $this->belongsTo(self::class, 'captions_asset_id');
    }

    /** @return BelongsTo<MediaFolder, $this> */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(MediaFolder::class, 'folder_id');
    }

    /** @return BelongsToMany<MediaTag, $this> */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(MediaTag::class, MediaConfig::table('asset_tag'), 'media_asset_id', 'media_tag_id');
    }

    /**
     * Derived, never stored: a stored flag is wrong for the whole interval
     * between the expiry passing and whatever would have written it running.
     */
    public function isExpired(): bool
    {
        return $this->license_expires_at !== null && $this->license_expires_at->isPast();
    }

    /**
     * Assets a content field may pick.
     *
     * An expired asset stays fully visible and manageable in the library — an
     * operator has to be able to see the thing whose licence lapsed in order to
     * renew or replace it — but it must not become a *new* usage. Exclusion at
     * the picker query is where that distinction lives.
     *
     * @param  Builder<MediaAsset>  $query
     * @return Builder<MediaAsset>
     */
    public function scopeSelectable(Builder $query): Builder
    {
        return $query->where(function (Builder $q): void {
            $q->whereNull('license_expires_at')->orWhere('license_expires_at', '>', now());
        });
    }

    /** An image can be cropped, focused and rendered; every other kind is delivered as-is. */
    public function isImage(): bool
    {
        return $this->kind === MediaKind::Image;
    }

    /**
     * Only assets of these kinds.
     *
     * @param  Builder<MediaAsset>  $query
     * @param  MediaKind|list<MediaKind>  $kinds
     * @return Builder<MediaAsset>
     */
    public function scopeOfKind(Builder $query, MediaKind|array $kinds): Builder
    {
        return $query->whereIn(
            $this->getTable().'.kind',
            array_map(static fn (MediaKind $kind): string => $kind->value, is_array($kinds) ? $kinds : [$kinds]),
        );
    }

    /**
     * Assets in this folder; with `$recursive`, in it or any folder below it.
     * Null is the library root: only the unfiled assets, or with `$recursive`
     * the whole library.
     *
     * @param  Builder<MediaAsset>  $query
     * @return Builder<MediaAsset>
     */
    public function scopeInFolder(Builder $query, ?MediaFolder $folder, bool $recursive = false): Builder
    {
        $column = $this->getTable().'.folder_id';

        if (! $folder instanceof MediaFolder) {
            return $recursive ? $query : $query->whereNull($column);
        }

        return $recursive
            ? $query->whereIn($column, $folder->subtreeQuery()->select($folder->getTable().'.id'))
            : $query->where($column, $folder->id);
    }

    /**
     * Only the assets this user may see: those at the root (when they may browse
     * it) and in folders they may open. A password folder's assets are absent
     * until it is unlocked. Without a user the signed-in one is meant; nobody
     * signed in sees nothing. Meant for admin surfaces, not public pages, which
     * show what they were told to show.
     *
     * @param  Builder<MediaAsset>  $query
     * @return Builder<MediaAsset>
     */
    public function scopeVisibleTo(Builder $query, ?Authenticatable $user = null): Builder
    {
        $user ??= auth()->user();
        $access = app(FolderAccess::class);

        if (! $user instanceof Authenticatable) {
            return $query->whereRaw('1 = 0');
        }

        // media.manage-all sees everything, including assets in folders the cache has not met.
        if ($access->seesEverything($user)) {
            return $query;
        }

        $column = $this->getTable().'.folder_id';
        $ids = $access->browsableFolderIds($user);
        $root = $access->canBrowse(null, $user);

        return $query->where(function (Builder $q) use ($column, $ids, $root): void {
            if ($root) {
                $q->whereNull($column);
            }

            $q->orWhereIntegerInRaw($column, $ids);
        });
    }

    /**
     * Only assets with no members-only or password folder on their path: what a
     * page open to the public may use (SPEC-0103 §5.4).
     *
     * @param  Builder<MediaAsset>  $query
     * @return Builder<MediaAsset>
     */
    public function scopeUnrestricted(Builder $query): Builder
    {
        $column = $this->getTable().'.folder_id';

        return $query->where(fn (Builder $q) => $q->whereNull($column)->orWhereIntegerInRaw($column, app(FolderAccess::class)->unrestrictedFolderIds()));
    }

    /** In a members-only or password-protected folder (anywhere on its path). */
    public function isRestricted(): bool
    {
        return $this->folder_id !== null && app(FolderAccess::class)->isRestricted($this);
    }

    /**
     * Whether a URL to this file may be issued to the signed-in user right now:
     * anything unrestricted, or a restricted file they may browse.
     */
    public function isDeliverable(): bool
    {
        return ! $this->isRestricted() || app(FolderAccess::class)->canBrowse($this);
    }

    /**
     * Filename, title or alt text contains the term. LIKE wildcards in the term are
     * matched literally.
     *
     * @param  Builder<MediaAsset>  $query
     * @return Builder<MediaAsset>
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $term = trim($term);

        if ($term === '') {
            return $query;
        }

        // `!` as the escape character: one ESCAPE clause that MySQL, PostgreSQL
        // and SQLite all read the same way, unlike a backslash.
        $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';

        return $query->where(fn (Builder $q) => $q
            ->whereRaw("filename like ? escape '!'", [$like])
            ->orWhereRaw("title like ? escape '!'", [$like])
            ->orWhereRaw("alt_text like ? escape '!'", [$like]));
    }

    /**
     * Carries any of the given tags (names are normalised as the library stores them).
     *
     * @param  Builder<MediaAsset>  $query
     * @param  string|list<string>  $names
     * @return Builder<MediaAsset>
     */
    public function scopeTagged(Builder $query, string|array $names): Builder
    {
        $names = array_values(array_filter(array_map(MediaTag::normalise(...), (array) $names), static fn (string $name): bool => $name !== ''));

        return $query->whereHas('tags', fn (Builder $q) => $q->whereIn(MediaConfig::table('tags').'.name', $names));
    }

    /**
     * The licence has lapsed.
     *
     * @param  Builder<MediaAsset>  $query
     * @return Builder<MediaAsset>
     */
    public function scopeExpired(Builder $query): Builder
    {
        return $query->whereNotNull('license_expires_at')->where('license_expires_at', '<=', now());
    }

    /**
     * The licence lapses within the next `$days` days (and has not yet).
     *
     * @param  Builder<MediaAsset>  $query
     * @return Builder<MediaAsset>
     */
    public function scopeExpiringWithin(Builder $query, int $days): Builder
    {
        return $query->whereNotNull('license_expires_at')
            ->where('license_expires_at', '>', now())
            ->where('license_expires_at', '<=', now()->addDays(max(0, $days)));
    }

    /**
     * Used somewhere, as the bound {@see UsageResolver} counts usage — the same
     * answer delete protection and the licence report give.
     *
     * @param  Builder<MediaAsset>  $query
     * @return Builder<MediaAsset>
     */
    public function scopeUsed(Builder $query): Builder
    {
        app(UsageResolver::class)->whereUsed($query->getQuery(), $this->getTable().'.id');

        return $query;
    }

    /** The asset with this public uuid, or null. */
    public static function findByUuid(string $uuid): ?self
    {
        return self::query()->where('uuid', $uuid)->first();
    }

    /**
     * A signed rendition URL: the one call a controller or Blade view needs.
     *
     * @param  'crop'|'contain'|null  $fit
     */
    public function url(?int $width = null, ?int $height = null, ?string $fit = null): string
    {
        return app(RenditionService::class)->url($this, $width, $height, $fit);
    }

    /**
     * A signed URL for the current file as it is: inline for an image, PDF,
     * video or audio file, an attachment for everything else. For an image this
     * is the original; use {@see self::url()} for a sized rendition.
     */
    public function fileUrl(): string
    {
        return FileDelivery::url($this);
    }

    /** A signed URL that always downloads: `Content-Disposition: attachment`. */
    public function downloadUrl(): string
    {
        return FileDelivery::url($this, true);
    }

    /** The captions file's URL, or null when none is attached or the signed-in user may not have it. */
    public function captionsUrl(): ?string
    {
        $captions = $this->captions;

        return $captions instanceof self && $captions->isDeliverable() ? $captions->fileUrl() : null;
    }

    /**
     * The short name of the file's format for link text: "PDF", "DOCX", "ZIP".
     * The localised kind label when the file has no extension.
     */
    public function formatLabel(): string
    {
        $extension = strtoupper(pathinfo($this->filename, PATHINFO_EXTENSION));

        return $extension === '' ? $this->kind->label() : $extension;
    }

    /** The title when there is one, otherwise the stored filename. */
    public function displayName(): string
    {
        return filled($this->title) ? (string) $this->title : $this->filename;
    }

    /** Bytes as a short human string. Presentation, but shared by three surfaces. */
    public function humanSize(): string
    {
        return self::formatBytes($this->size_bytes);
    }

    /** "117012" as "114.3 KB": the unit a person reads, not the one a disk counts. */
    public static function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $size = (float) $bytes;
        $unit = 0;

        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        return ($unit === 0 ? (string) (int) $size : number_format($size, 1)).' '.$units[$unit];
    }

    public function dimensions(): ?string
    {
        if ($this->width === null || $this->height === null) {
            return null;
        }

        return $this->width.' × '.$this->height;
    }
}
