<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Pages\Concerns;

use Banyan\FilamentMediaLibrary\Contracts\UsageResolver;
use Banyan\FilamentMediaLibrary\Models\MediaAsset;
use Closure;
use Filament\Notifications\Notification;
use Throwable;

/**
 * The try, notify and catch part of a write action, written once.
 *
 * It only reports the outcome. The caller still checks the gate before it
 * calls {@see attempt()}, because `visible()` only hides a button.
 * The class that uses it must also use `MediaText`.
 */
trait RunsMediaActions
{
    /**
     * Run `$work`, then show the success title. If it throws, show the failure
     * title with the exception message instead.
     *
     * `$work` can return `false` to stop quietly, when nothing was changed and
     * another message has already been shown. `$successTitle` can be a closure
     * that takes the result of `$work`, for a title that depends on it.
     *
     * @param  string|Closure(mixed): string  $successTitle
     * @param  Closure(): mixed  $work
     * @return mixed what `$work` returned; null when it failed or stopped
     */
    protected function attempt(string|Closure $successTitle, string $failureTitle, Closure $work): mixed
    {
        try {
            $result = $work();

            if ($result === false) {
                return null;
            }

            Notification::make()->success()->title($successTitle instanceof Closure ? $successTitle($result) : $successTitle)->send();

            return $result;
        } catch (Throwable $e) {
            Notification::make()->danger()->title($failureTitle)->body($e->getMessage())->send();

            return null;
        } finally {
            $this->mediaActionFinished();
        }
    }

    /** Called after every {@see attempt()}, so a page can drop what it read before the write. */
    protected function mediaActionFinished(): void {}

    /** The text of a delete confirmation for this asset, or "not found" when the user may not touch it. */
    protected function deleteConfirmation(?MediaAsset $asset): string
    {
        if (! $asset instanceof MediaAsset) {
            return self::text('media.not_found');
        }

        $count = app(UsageResolver::class)->count($asset->id);

        return $count > 0
            ? self::text('media.delete_confirm_used', ['name' => $asset->filename, 'count' => $count])
            : self::text('media.delete_confirm', ['name' => $asset->filename]);
    }
}
