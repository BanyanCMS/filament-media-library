<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Models;

use Banyan\FilamentMediaLibrary\Support\MediaConfig;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A manual tag. There is no automatic tagging and no taxonomy tree: a flat,
 * editor-authored keyword is the whole of it this pass.
 *
 * @property int $id
 * @property string $name
 */
final class MediaTag extends Model
{
    public function getTable(): string
    {
        return MediaConfig::table('tags');
    }

    protected $guarded = [];

    /** @return BelongsToMany<MediaAsset, $this> */
    public function assets(): BelongsToMany
    {
        return $this->belongsToMany(MediaAsset::class, MediaConfig::table('asset_tag'), 'media_tag_id', 'media_asset_id');
    }

    /** One casing, one tag. "Hero" and "hero" filtering differently is a bug, not a feature. */
    public static function normalise(string $name): string
    {
        return mb_strtolower(trim($name));
    }
}
