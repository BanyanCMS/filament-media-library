<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Governance;

use Banyan\FilamentMediaLibrary\Contracts\UsageResolver;
use Banyan\FilamentMediaLibrary\Support\MediaConfig;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Database\ConnectionInterface;

/**
 * Reads the operational licence picture without mutating asset rows.
 *
 * "Still used" is the {@see UsageResolver}'s answer, so a stale usage row the
 * resolver does not count as live never raises a false alert.
 */
final readonly class MediaExpiryReport
{
    public function __construct(
        private ConnectionInterface $connection,
        private UsageResolver $usages,
    ) {}

    public function summary(?DateTimeInterface $now = null, ?int $warningDays = null): MediaExpirySummary
    {
        $pointInTime = $now === null
            ? new DateTimeImmutable('now')
            : DateTimeImmutable::createFromInterface($now);
        $days = $this->normaliseWarningDays($warningDays);
        $warningEnd = $pointInTime->modify(sprintf('+%d days', $days));

        return new MediaExpirySummary(
            expired: $this->countBetween(null, $pointInTime),
            expiredReferenced: $this->countBetween(null, $pointInTime, referenced: true),
            expiringSoon: $this->countBetween($pointInTime, $warningEnd),
            expiringSoonReferenced: $this->countBetween($pointInTime, $warningEnd, referenced: true),
            warningDays: $days,
        );
    }

    private function normaliseWarningDays(?int $warningDays): int
    {
        return $warningDays === null ? MediaConfig::expiryWarningDays() : max(1, $warningDays);
    }

    private function countBetween(
        ?DateTimeInterface $from,
        DateTimeInterface $until,
        bool $referenced = false,
    ): int {
        $query = $this->connection->table(MediaConfig::table('assets').' as a')
            ->whereNotNull('a.license_expires_at')
            ->where('a.license_expires_at', '<=', $until);

        if ($from !== null) {
            $query->where('a.license_expires_at', '>', $from);
        }

        if ($referenced) {
            $this->usages->whereUsed($query, 'a.id');
        }

        return (int) $query->count();
    }
}
