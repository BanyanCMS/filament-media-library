<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Examples\Articles\Models;

use Banyan\FilamentMediaLibrary\Examples\Articles\Concerns\RecordsMediaUsage;
use Banyan\FilamentMediaLibrary\Models\MediaAsset;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $title
 * @property string $body
 * @property int|null $hero_id
 * @property list<int>|null $gallery
 * @property int|null $author_id
 */
class Article extends Model
{
    use RecordsMediaUsage;

    protected $table = 'example_articles';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['gallery' => 'array'];
    }

    public static function mediaFields(): array
    {
        return ['hero_id', 'gallery'];
    }

    public function hero(): ?MediaAsset
    {
        return $this->hero_id === null ? null : MediaAsset::query()->find($this->hero_id);
    }
}
