<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Examples\Articles\Concerns;

use Banyan\FilamentMediaLibrary\Usage\UsageRecorder;

/**
 * The pattern every model that holds media follows.
 *
 * A `MediaPicker` only stores ids. Delete protection, the licence report and
 * the "used by" table on the asset page read the usages table, so the model
 * must keep that table in step: sync on save, forget on delete.
 *
 * Declare which attributes hold assets in `mediaFields()` and the rest is done.
 */
trait RecordsMediaUsage
{
    /** @return list<string> attribute names holding one asset id or a list of ids */
    abstract public static function mediaFields(): array;

    public static function bootRecordsMediaUsage(): void
    {
        static::saved(function (self $model): void {
            $recorder = app(UsageRecorder::class);

            foreach (static::mediaFields() as $field) {
                $recorder->sync($model, $field, $model->assetIds($field));
            }
        });

        static::deleted(function (self $model): void {
            app(UsageRecorder::class)->forget($model);
        });
    }

    /** @return list<int> */
    public function assetIds(string $field): array
    {
        $value = $this->getAttribute($field);

        return array_values(array_map(intval(...), array_filter(
            is_array($value) ? $value : [$value],
            is_numeric(...),
        )));
    }
}
