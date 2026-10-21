<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Models;

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
