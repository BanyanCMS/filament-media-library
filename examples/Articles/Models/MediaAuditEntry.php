<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Examples\Articles\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $event
 * @property string $actor_id
 * @property int $asset_id
 * @property array<string, scalar|null> $payload
 */
class MediaAuditEntry extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'example_media_audit';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['payload' => 'array'];
    }
}
