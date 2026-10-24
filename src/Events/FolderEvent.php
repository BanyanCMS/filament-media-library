<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Events;

/**
 * The shared part of every event about one folder.
 *
 * A subclass sets {@see NAME} and returns its own {@see payload()}. It carries
 * ids, counts and mode names only, because a folder name can be personal data.
 */
abstract readonly class FolderEvent implements MediaFolderEvent
{
    /** A stable dotted name, e.g. `media.folder.created`. Every subclass sets its own. */
    public const string NAME = '';

    public function __construct(
        public int $folderId,
        public string $actor,
    ) {}

    public function name(): string
    {
        return static::NAME;
    }

    public function subjectType(): string
    {
        return 'media_folder';
    }

    public function subjectId(): string
    {
        return (string) $this->folderId;
    }

    public function folderId(): int
    {
        return $this->folderId;
    }

    public function actorId(): string
    {
        return $this->actor;
    }
}
