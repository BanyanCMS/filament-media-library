<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Events;

/** A folder was created. Carries ids and depth only: a folder name can be personal data. */
final readonly class FolderCreated extends FolderEvent
{
    public const string NAME = 'media.folder.created';

    public function __construct(
        int $folderId,
        public string $uuid,
        public ?int $parentId,
        public int $depth,
        string $actor,
    ) {
        parent::__construct($folderId, $actor);
    }

    public function payload(): array
    {
        return [
            'folder_id' => $this->folderId,
            'uuid' => $this->uuid,
            'parent_id' => $this->parentId,
            'depth' => $this->depth,
        ];
    }
}
