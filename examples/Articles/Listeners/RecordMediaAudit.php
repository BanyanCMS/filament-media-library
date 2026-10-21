<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Examples\Articles\Listeners;

use Banyan\FilamentMediaLibrary\Events\MediaEvent;
use Banyan\FilamentMediaLibrary\Examples\Articles\Models\MediaAuditEntry;

/**
 * One row per change the library makes.
 *
 * The library dispatches inside the write's transaction, so if this insert
 * fails the change is rolled back: the log can never be missing an entry.
 * The event payload is references only (ids, sizes, flags), so what lands here
 * holds no alt text and no filename.
 */
final class RecordMediaAudit
{
    public function handle(MediaEvent $event): void
    {
        MediaAuditEntry::query()->create([
            'event' => $event->name(),
            'actor_id' => $event->actorId(),
            'asset_id' => $event->assetId(),
            'payload' => $event->payload(),
        ]);
    }
}
