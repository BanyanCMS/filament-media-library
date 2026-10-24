<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Events;

/** A folder, with its whole subtree, moved under another parent (null is the root). Carries ids and depth only: a folder name can be personal data. */
final readonly class FolderMoved extends FolderEvent
{
    public const string NAME = 'media.folder.moved';

    public function __construct(
        int $folderId,
        public ?int $fromParentId,
        public ?int $toParentId,
        string $actor,
    ) {
        parent::__construct($folderId, $actor);
    }

    public function payload(): array
    {
        return [
            'folder_id' => $this->folderId,
            'from_parent_id' => $this->fromParentId,
            'to_parent_id' => $this->toParentId,
        ];
    }
}
