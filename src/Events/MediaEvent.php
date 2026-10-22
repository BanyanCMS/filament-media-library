<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Events;

/**
 * Every change the library makes to an asset or a folder.
 *
 * Dispatched synchronously inside the write's transaction, so a listener that
 * throws rolls the write back: an audit trail built on these events never
 * describes a change that did not happen, and never misses one that did.
 *
 * Listen to this interface to receive all of them. The payload carries
 * references (ids, sizes, flags), never alt text, a filename or a folder name,
 * which can name a person. Use {@see subjectType()} and {@see subjectId()} to
 * file an entry; narrow to {@see MediaAssetEvent} or {@see MediaFolderEvent}
 * when you need the typed id.
 */
interface MediaEvent
{
    /** A stable dotted name, e.g. `media.asset.uploaded`. */
    public function name(): string;

    /** What the change happened to: `media_asset` or `media_folder`. */
    public function subjectType(): string;

    /** The subject's id, as a string, for hosts that file entries by (type, id). */
    public function subjectId(): string;

    /** Whoever made the change, as the host's user id. */
    public function actorId(): string;

    /** @return array<string, scalar|null> */
    public function payload(): array;
}
