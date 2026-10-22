<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Events;

/** A change to one folder (create, rename, move, delete). */
interface MediaFolderEvent extends MediaEvent
{
    public function folderId(): int;
}
