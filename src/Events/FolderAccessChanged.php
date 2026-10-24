<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Events;

/**
 * A folder's access mode or password changed. `from` and `to` are mode names; a
 * password change within the password mode has `from === to`. The payload never
 * carries the password, its hash or a fingerprint of either.
 */
final readonly class FolderAccessChanged extends FolderEvent
{
    public const string NAME = 'media.folder.access_changed';

    public function __construct(
        int $folderId,
        public string $from,
        public string $to,
        public int $membersCount,
        string $actor,
    ) {
        parent::__construct($folderId, $actor);
    }

    public function payload(): array
    {
        return [
            'folder_id' => $this->folderId,
            'from' => $this->from,
            'to' => $this->to,
            'members_count' => $this->membersCount,
        ];
    }
}
