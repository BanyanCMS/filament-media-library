<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Events;

/** A member was added to, re-roled in, or removed from a folder. User ids only, never a name. */
final readonly class FolderMembersChanged extends FolderEvent
{
    public const string NAME = 'media.folder.members_changed';

    /** @param 'added'|'role_changed'|'removed' $change */
    public function __construct(
        int $folderId,
        public int $userId,
        public string $change,
        public ?string $role,
        public int $membersCount,
        string $actor,
    ) {
        parent::__construct($folderId, $actor);
    }

    public function payload(): array
    {
        return [
            'folder_id' => $this->folderId,
            'user_id' => $this->userId,
            'change' => $this->change,
            'role' => $this->role,
            'members_count' => $this->membersCount,
        ];
    }
}
