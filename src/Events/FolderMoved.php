<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Events;

/** A folder, with its whole subtree, moved under another parent (null is the root). Carries ids and depth only: a folder name can be personal data. */
final readonly class FolderMoved implements MediaFolderEvent
{
    use FolderSubject;

    public function __construct(
        public int $id,
        public ?int $fromParentId,
        public ?int $toParentId,
        public string $actor,
    ) {}

    public function name(): string
    {
        return 'media.folder.moved';
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
            'from_parent_id' => $this->fromParentId,
            'to_parent_id' => $this->toParentId,
        ];
    }
}
