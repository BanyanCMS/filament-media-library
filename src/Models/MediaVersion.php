<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Models;

use Banyan\FilamentMediaLibrary\Support\MediaConfig;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One set of bytes an asset has had.
 *
 * @property int $id
 * @property int $media_asset_id
 * @property string $disk
 * @property string $path
 * @property string $checksum
 * @property int $size_bytes
 * @property int|null $width
 * @property int|null $height
 * @property int|null $uploaded_by
 * @property CarbonInterface|null $created_at
 */
final class MediaVersion extends Model
{
    public function getTable(): string
    {
        return MediaConfig::table('versions');
    }

    protected $guarded = [];

    /** Only `created_at`: a version is written once and never updated. */
    public const UPDATED_AT = null;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<MediaAsset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'media_asset_id');
    }

    public function shortChecksum(): string
    {
        return substr($this->checksum, 0, 12);
    }
}
