<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Usage;

use Banyan\FilamentMediaLibrary\Support\MediaConfig;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;

/**
 * Keeps the usages table in step with the models that reference assets.
 *
 * Call {@see sync()} wherever a model's media field is saved and
 * {@see forget()} when the model is deleted. Delete protection and the
 * licence report are only as good as these rows.
 */
final readonly class UsageRecorder
{
    public function __construct(private ConnectionInterface $connection) {}

    /**
     * Replace the assets one field of one model uses.
     *
     * @param  list<int>  $assetIds
     */
    public function sync(Model $owner, string $field, array $assetIds, ?string $locale = null): void
    {
        $type = $owner->getMorphClass();
        $id = $this->key($owner);

        $this->connection->transaction(function () use ($type, $id, $field, $assetIds, $locale): void {
            $this->table()
                ->where('usable_type', $type)
                ->where('usable_id', $id)
                ->where('field', $field)
                ->where('locale', $locale)
                ->delete();

            $now = now();
            $rows = [];

            foreach (array_unique($assetIds) as $assetId) {
                $rows[] = [
                    'media_asset_id' => $assetId,
                    'usable_type' => $type,
                    'usable_id' => $id,
                    'field' => $field,
                    'locale' => $locale,
                    'created_at' => $now,
                ];
            }

            if ($rows !== []) {
                $this->table()->insert($rows);
            }
        });
    }

    /** Drop every usage a model holds, call it when the model is deleted. */
    public function forget(Model $owner): void
    {
        $this->table()
            ->where('usable_type', $owner->getMorphClass())
            ->where('usable_id', $this->key($owner))
            ->delete();
    }

    private function key(Model $owner): string
    {
        $key = $owner->getKey();

        if (! is_int($key) && ! is_string($key)) {
            throw new InvalidArgumentException('Only a saved model with an int or string key can use media.');
        }

        return (string) $key;
    }

    private function table(): Builder
    {
        return $this->connection->table(MediaConfig::table('usages'));
    }
}
