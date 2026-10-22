<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Events;

/** A folder was created. Carries ids and depth only: a folder name can be personal data. */
final readonly class FolderCreated implements MediaFolderEvent
{
    use FolderSubject;

    public function __construct(
        public int $id,
        public string $uuid,
        public ?int $parentId,
        public int $depth,
        public string $actor,
    ) {}

    public function name(): string
    {
        return 'media.folder.created';
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
            'parent_id' => $this->parentId,
            'depth' => $this->depth,
        ];
    }
}
