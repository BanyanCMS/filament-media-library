<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Models;

use Banyan\FilamentMediaLibrary\Contracts\UsageResolver;
use Banyan\FilamentMediaLibrary\Rendering\RenditionService;
use Banyan\FilamentMediaLibrary\Support\MediaConfig;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A managed image. Flat, not tree-scoped: an asset is reused across sites and
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
 * @property string $alt_text
 * @property float $focal_x
 * @property float $focal_y
 * @property CarbonInterface|null $license_expires_at
 * @property int|null $current_version_id
 * @property int|null $uploaded_by
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

    /**
     * Filename or alt text contains the term. LIKE wildcards in the term are
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
