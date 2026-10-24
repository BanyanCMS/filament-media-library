<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Events;

/** A folder was renamed. Carries ids and depth only: a folder name can be personal data. */
final readonly class FolderRenamed extends FolderEvent
{
    public const string NAME = 'media.folder.renamed';

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
