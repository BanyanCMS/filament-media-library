<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Events;

/** The {@see MediaEvent} subject methods of an event about one asset. */
trait AssetSubject
{
    public function subjectType(): string
    {
        return 'media_asset';
    }

    public function subjectId(): string
    {
        return (string) $this->assetId();
    }
}
