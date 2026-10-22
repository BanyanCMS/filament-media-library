<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Events;

/**
 * A folder's access mode or password changed. `from` and `to` are mode names; a
 * password change within the password mode has `from === to`. The payload never
 * carries the password, its hash or a fingerprint of either.
 */
final readonly class FolderAccessChanged implements MediaFolderEvent
{
    use FolderSubject;

    public function __construct(
        public int $id,
        public string $from,
        public string $to,
        public int $membersCount,
        public string $actor,
    ) {}

    public function name(): string
    {
        return 'media.folder.access_changed';
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
            'from' => $this->from,
            'to' => $this->to,
            'members_count' => $this->membersCount,
        ];
    }
}
