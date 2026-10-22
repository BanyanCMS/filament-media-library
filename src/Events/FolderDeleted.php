<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Events;

/** An empty folder was deleted. Carries ids and depth only: a folder name can be personal data. */
final readonly class FolderDeleted implements MediaFolderEvent
{
    use FolderSubject;

    public function __construct(
        public int $id,
        public string $uuid,
        public string $actor,
    ) {}

    public function name(): string
    {
        return 'media.folder.deleted';
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
            'uuid' => $this->uuid,
        ];
    }
}
