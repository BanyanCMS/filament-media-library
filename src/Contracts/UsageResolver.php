<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Contracts;

use Banyan\FilamentMediaLibrary\Usage\AssetUsage;
use Banyan\FilamentMediaLibrary\Usage\DatabaseUsageResolver;
use Banyan\FilamentMediaLibrary\Usage\UsageRecorder;
use Illuminate\Database\Query\Builder;

/**
 * Where an asset is used.
 *
 * The library never guesses at the host's schema: it asks this contract. The
 * default ({@see DatabaseUsageResolver})
 * reads the rows {@see UsageRecorder}
 * writes; an app that already knows its references binds its own.
 *
 * "Used" means live: a usage whose owner has been deleted must stop counting,
 * or a lapsed licence raises a false alert and a safe delete is refused.
 */
interface UsageResolver
{
    /** @return list<AssetUsage> */
    public function usages(int $assetId): array;

    public function count(int $assetId): int;

    /**
     * One query for a whole library page.
     *
     * @param  list<int>  $assetIds
     * @return array<int, int> asset id => live usage count; unused assets may be absent
     */
    public function counts(array $assetIds): array;

    /** Constrain `$query` to rows whose `$assetIdColumn` names an asset in use. */
    public function whereUsed(Builder $query, string $assetIdColumn): void;
}
