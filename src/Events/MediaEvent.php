<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Events;

/**
 * Every change the library makes to an asset.
 *
 * Dispatched synchronously inside the write's transaction, so a listener that
 * throws rolls the write back: an audit trail built on these events never
 * describes a change that did not happen, and never misses one that did.
 *
 * Listen to this interface to receive all of them. The payload carries
 * references (ids, sizes, flags), never alt text or a filename, which can name
 * a person.
 */
interface MediaEvent
{
    /** A stable dotted name, e.g. `media.asset.uploaded`. */
    public function name(): string;

    public function assetId(): int;

    /** Whoever made the change, as the host's user id. */
    public function actorId(): string;

    /** @return array<string, scalar|null> */
    public function payload(): array;
}
