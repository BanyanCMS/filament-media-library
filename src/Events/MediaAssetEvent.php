<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Events;

/** A change to one asset (upload, replacement, edit, move, delete). */
interface MediaAssetEvent extends MediaEvent
{
    public function assetId(): int;
}
