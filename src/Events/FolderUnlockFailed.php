<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Events;

/**
 * A wrong (or throttled) attempt to unlock a password-protected folder.
 * `attempts` is how many failures the user has in the current window. The
 * attempted password is never part of the event.
 */
final readonly class FolderUnlockFailed extends FolderEvent
{
    public const string NAME = 'media.folder.unlock_failed';

    public function __construct(
        int $folderId,
        public int $attempts,
        string $actor,
    ) {
        parent::__construct($folderId, $actor);
    }

    public function payload(): array
    {
        return [
            'folder_id' => $this->folderId,
            'attempts' => $this->attempts,
        ];
    }
}
