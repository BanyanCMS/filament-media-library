<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Governance;

use Banyan\FilamentMediaLibrary\Access\FolderAccess;
use Banyan\FilamentMediaLibrary\Contracts\UsageResolver;
use Banyan\FilamentMediaLibrary\Models\MediaAsset;
use Banyan\FilamentMediaLibrary\Support\MediaConfig;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;

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

    /**
     * @param  Authenticatable|null  $visibleTo  count only the assets this user may see (folder access);
     *                                           null counts the whole library, as a scheduled job does
     */
    public function summary(?DateTimeInterface $now = null, ?int $warningDays = null, ?Authenticatable $visibleTo = null): MediaExpirySummary
    {
        $pointInTime = $now === null
            ? new DateTimeImmutable('now')
            : DateTimeImmutable::createFromInterface($now);
        $days = $this->normaliseWarningDays($warningDays);
        $warningEnd = $pointInTime->modify(sprintf('+%d days', $days));

        return new MediaExpirySummary(
            expired: $this->countBetween(null, $pointInTime, viewer: $visibleTo),
            expiredReferenced: $this->countBetween(null, $pointInTime, referenced: true, viewer: $visibleTo),
            expiringSoon: $this->countBetween($pointInTime, $warningEnd, viewer: $visibleTo),
            expiringSoonReferenced: $this->countBetween($pointInTime, $warningEnd, referenced: true, viewer: $visibleTo),
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
        ?Authenticatable $viewer = null,
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

        if ($viewer instanceof Authenticatable) {
            $this->restrictTo($query, $viewer);
        }

        return (int) $query->count();
    }

    /** Keep only the rows in the root or in a folder the viewer may open, as {@see MediaAsset::scopeVisibleTo()} does. */
    private function restrictTo(Builder $query, Authenticatable $viewer): void
    {
        $access = app(FolderAccess::class);

        if ($access->seesEverything($viewer)) {
            return;
        }

        $root = $access->canBrowse(null, $viewer);
        $ids = $access->browsableFolderIds($viewer);

        $query->where(function (Builder $q) use ($root, $ids): void {
            if ($root) {
                $q->whereNull('a.folder_id');
            }

            $q->orWhereIntegerInRaw('a.folder_id', $ids);
        });
    }
}
