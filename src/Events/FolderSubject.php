<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Events;

/** The {@see MediaEvent} subject methods of an event about one folder. */
trait FolderSubject
{
    public function subjectType(): string
    {
        return 'media_folder';
    }

    public function subjectId(): string
    {
        return (string) $this->folderId();
    }
}
