<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Events;

/** An empty folder was deleted. Carries ids and depth only: a folder name can be personal data. */
final readonly class FolderDeleted extends FolderEvent
{
    public const string NAME = 'media.folder.deleted';

    public function __construct(
        int $folderId,
        public string $uuid,
        string $actor,
    ) {
        parent::__construct($folderId, $actor);
    }

    public function payload(): array
    {
        return [
            'folder_id' => $this->folderId,
            'uuid' => $this->uuid,
        ];
    }
}
