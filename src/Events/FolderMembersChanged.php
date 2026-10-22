<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Events;

/** A member was added to, re-roled in, or removed from a folder. User ids only, never a name. */
final readonly class FolderMembersChanged implements MediaFolderEvent
{
    use FolderSubject;

    /** @param 'added'|'role_changed'|'removed' $change */
    public function __construct(
        public int $id,
        public int $userId,
        public string $change,
        public ?string $role,
        public int $membersCount,
        public string $actor,
    ) {}

    public function name(): string
    {
        return 'media.folder.members_changed';
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
            'user_id' => $this->userId,
            'change' => $this->change,
            'role' => $this->role,
            'members_count' => $this->membersCount,
        ];
    }
}
