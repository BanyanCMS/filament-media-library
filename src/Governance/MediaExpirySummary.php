<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Governance;

/**
 * A point-in-time licence health snapshot for the media library.
 *
 * Counts are calculated at read time. Expiry is a date boundary, not a
 * denormalised status flag that can go stale between scheduled jobs.
 */
final readonly class MediaExpirySummary
{
    public function __construct(
        public int $expired,
        public int $expiredReferenced,
        public int $expiringSoon,
        public int $expiringSoonReferenced,
        public int $warningDays,
    ) {}

    public function hasIssues(): bool
    {
        return $this->expired > 0 || $this->expiringSoon > 0;
    }
}
