<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Admin\Concerns;

use Banyan\FilamentMediaLibrary\Access\FolderAccess;
use Banyan\FilamentMediaLibrary\Contracts\UsageResolver;
use Banyan\FilamentMediaLibrary\Exceptions\InvalidFolderAccess;
use Banyan\FilamentMediaLibrary\Models\MediaAsset;
use Banyan\FilamentMediaLibrary\Models\MediaFolder;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;

/**
 * Where a user may put files, and what moving files into a restricted folder costs.
 *
 * Shared by the library page and the asset page. Both questions are answered
 * by {@see FolderAccess} and {@see UsageResolver} on the server, inside the
 * action handler: the form's toggle is only the way the editor says "yes".
 */
trait GuardsRestrictedMoves
{
    /**
     * How many of these files are still in use, when `$to` is a restricted
     * folder: public pages cannot show a file from there (SPEC-0103 §5.4), so
     * the editor must be told and must confirm. Zero when nothing is lost.
     *
     * @param  iterable<MediaAsset>|null  $assets
     */
    private function usedFileCount(?MediaFolder $to, ?iterable $assets): int
    {
        return $to instanceof MediaFolder && $assets !== null && $to->isRestricted() ? $this->usedAmong($assets) : 0;
    }

    /**
     * How many of these files are in use somewhere.
     *
     * @param  iterable<MediaAsset>  $assets
     */
    private function usedAmong(iterable $assets): int
    {
        $ids = [];

        foreach ($assets as $asset) {
            $ids[] = $asset->id;
        }

        return $ids === [] ? 0 : count(array_filter(app(UsageResolver::class)->counts($ids), static fn (int $count): bool => $count > 0));
    }

    /**
     * Server-side half of the usage warning: the toggle is only a form field, so
     * the handler works the count out again and refuses without the confirmation.
     *
     * @param  iterable<MediaAsset>  $assets
     * @param  array<string, mixed>  $data
     */
    private function usageConfirmed(?MediaFolder $to, iterable $assets, array $data): bool
    {
        if ($this->usedFileCount($to, $assets) === 0 || ($data['confirm_usage'] ?? false) === true) {
            return true;
        }

        Notification::make()->danger()->title(self::text('media.access.confirm_usage'))->send();

        return false;
    }

    /** @param \Closure(Get): int $count */
    private function confirmUsageField(\Closure $count): Toggle
    {
        return Toggle::make('confirm_usage')
            ->label(self::text('media.access.confirm_usage'))
            ->helperText(fn (Get $get): string => self::choice('media.access.usage_warning', $count($get)))
            ->visible(fn (Get $get): bool => $count($get) > 0)
            ->accepted(fn (Get $get): bool => $count($get) > 0);
    }

    /** A destination from a form value for a live check: unknown or unusable simply counts as nothing. */
    private function safeFolder(mixed $uuid): ?MediaFolder
    {
        $folder = is_string($uuid) && $uuid !== '' ? MediaFolder::findByUuid($uuid) : null;

        return $folder instanceof MediaFolder && app(FolderAccess::class)->canManage($folder) ? $folder : null;
    }

    /**
     * The folder a form value names (null: the root), provided the user may put
     * files there. A folder that does not exist and one they may not use are
     * refused with the same words, so the refusal confirms nothing.
     *
     * @throws InvalidFolderAccess
     */
    private function folderFrom(mixed $uuid): ?MediaFolder
    {
        $folder = is_string($uuid) && $uuid !== '' ? MediaFolder::findByUuid($uuid) : null;

        // An unknown uuid must fail, not fall through to "the root": the folder may
        // have been deleted since the form opened, and filing into the root would be a surprise.
        if (is_string($uuid) && $uuid !== '' && ! $folder instanceof MediaFolder) {
            throw InvalidFolderAccess::make(self::text('media.access.destination_refused'));
        }

        $this->assertDestinationAllowed($folder);

        return $folder;
    }

    /** @throws InvalidFolderAccess */
    private function assertDestinationAllowed(?MediaFolder $folder): void
    {
        if (! app(FolderAccess::class)->canManage($folder)) {
            throw InvalidFolderAccess::make(self::text('media.access.destination_refused'));
        }
    }
}
