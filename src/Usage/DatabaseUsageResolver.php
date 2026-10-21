<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Usage;

use Banyan\FilamentMediaLibrary\Contracts\UsageResolver;
use Banyan\FilamentMediaLibrary\Support\MediaConfig;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;

/**
 * Reads the rows {@see UsageRecorder} writes.
 *
 * Rows are trusted as live: {@see UsageRecorder::forget()} is how a deleted
 * owner stops counting. An app whose references live elsewhere binds its own
 * {@see UsageResolver} instead.
 */
final readonly class DatabaseUsageResolver implements UsageResolver
{
    public function __construct(private ConnectionInterface $connection) {}

    public function usages(int $assetId): array
    {
        $usages = [];

        foreach ($this->table()->where('media_asset_id', $assetId)->orderBy('usable_type')->orderBy('usable_id')->orderBy('field')->get() as $row) {
            $type = is_string($row->usable_type) ? $row->usable_type : '';
            $id = is_scalar($row->usable_id) ? (string) $row->usable_id : '';
            $name = Str::headline(class_basename($type));

            $usages[] = new AssetUsage(
                label: $name.' #'.$id,
                type: $name,
                field: is_string($row->field) ? $row->field : '',
                locale: is_string($row->locale) ? $row->locale : null,
            );
        }

        return $usages;
    }

    public function count(int $assetId): int
    {
        return $this->table()->where('media_asset_id', $assetId)->count();
    }

    public function counts(array $assetIds): array
    {
        if ($assetIds === []) {
            return [];
        }

        $counts = [];

        foreach ($this->table()
            ->whereIn('media_asset_id', $assetIds)
            ->groupBy('media_asset_id')
            ->get(['media_asset_id', $this->connection->raw('COUNT(*) as usage_count')]) as $row) {
            if (is_numeric($row->media_asset_id) && is_numeric($row->usage_count)) {
                $counts[(int) $row->media_asset_id] = (int) $row->usage_count;
            }
        }

        return $counts;
    }

    public function whereUsed(Builder $query, string $assetIdColumn): void
    {
        $query->whereExists(function (Builder $subquery) use ($assetIdColumn): void {
            $subquery->selectRaw('1')
                ->from(MediaConfig::table('usages').' as u')
                ->whereColumn('u.media_asset_id', $assetIdColumn);
        });
    }

    private function table(): Builder
    {
        return $this->connection->table(MediaConfig::table('usages'));
    }
}
