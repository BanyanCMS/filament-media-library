<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Events;

/**
 * A wrong (or throttled) attempt to unlock a password-protected folder.
 * `attempts` is how many failures the user has in the current window. The
 * attempted password is never part of the event.
 */
final readonly class FolderUnlockFailed implements MediaFolderEvent
{
    use FolderSubject;

    public function __construct(
        public int $id,
        public int $attempts,
        public string $actor,
    ) {}

    public function name(): string
    {
        return 'media.folder.unlock_failed';
    }

    public function folderId(): int
    {
        return $this->id;
    }

    public function actorId(): string
    {
        return $this->actor;
    }

    public function payload(): array
    {
        return [
            'folder_id' => $this->id,
            'attempts' => $this->attempts,
        ];
    }
}
