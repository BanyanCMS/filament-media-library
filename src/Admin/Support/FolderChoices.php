<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Admin\Support;

use Banyan\FilamentMediaLibrary\Access\FolderAccess;
use Banyan\FilamentMediaLibrary\Enums\FolderAccessMode;
use Banyan\FilamentMediaLibrary\Models\MediaFolder;

/**
 * The folder lists the library's screens build: the sidebar tree, and the flat
 * "Reports / 2025" options of a move or picker select.
 */
final class FolderChoices
{
    /**
     * The folder tree as nodes, top-level folders tagged with `$siteId` first.
     * Only folders the signed-in user may see are loaded: a folder they may not
     * is not hidden in the view, it is never read, so it cannot reach the payload.
     *
     * @param  list<string>  $expanded  uuids the editor opened
     * @return list<FolderNode>
     */
    public static function tree(?MediaFolder $current, array $expanded, ?int $siteId): array
    {
        $byParent = [];

        $access = app(FolderAccess::class);
        $locked = array_flip($access->lockedFolderIds());

        foreach (MediaFolder::query()->visibleTo()->orderBy('name')->get() as $folder) {
            $byParent[$folder->parent_id ?? 0][] = $folder;
        }

        // The path to the current folder is always open, so it is never hidden inside a collapsed branch.
        $open = array_flip($expanded);
        $openIds = $current instanceof MediaFolder ? array_flip($current->ancestorIds()) : [];

        $build = static function (int $parentId) use (&$build, $byParent, $open, $openIds, $current, $access, $locked): array {
            $nodes = [];

            foreach ($byParent[$parentId] ?? [] as $folder) {
                $nodes[] = new FolderNode(
                    $folder,
                    $build($folder->id),
                    isset($open[$folder->uuid]) || isset($openIds[$folder->id]) || $current?->id === $folder->id,
                    $current?->id === $folder->id,
                    isset($locked[$folder->id]),
                    $access->effectiveMode($folder) === FolderAccessMode::Members,
                );
            }

            return $nodes;
        };

        return self::siteFirst($build(0), $siteId);
    }

    /**
     * Top-level folders first for the current site, each group by name.
     *
     * @template T of FolderNode|MediaFolder
     *
     * @param  list<T>  $items
     * @return list<T>
     */
    public static function siteFirst(array $items, ?int $siteId): array
    {
        if ($siteId === null) {
            return $items;
        }

        $folderOf = static fn (FolderNode|MediaFolder $item): MediaFolder => $item instanceof FolderNode ? $item->folder : $item;

        // A stable partition: the incoming order (by name) holds within each group.
        $mine = array_values(array_filter($items, static fn ($item): bool => $folderOf($item)->site_id === $siteId));
        $rest = array_values(array_filter($items, static fn ($item): bool => $folderOf($item)->site_id !== $siteId));

        return [...$mine, ...$rest];
    }

    /**
     * Folders as uuid => "Parent / Child", for a select. A folder's own subtree
     * can be left out, so it cannot be offered as its own new parent. Only
     * folders the user may put files in are offered (`$manageable`), or only
     * those they may see; either way every ancestor on a label is open to them.
     *
     * @return array<string, string>
     */
    public static function options(?MediaFolder $excludeSubtreeOf = null, bool $manageable = true): array
    {
        $query = MediaFolder::query()->orderBy('path');
        $manageable ? $query->manageableBy() : $query->visibleTo();

        if ($excludeSubtreeOf instanceof MediaFolder) {
            $excluded = $excludeSubtreeOf->subtreeQuery()->pluck($excludeSubtreeOf->getTable().'.id')->all();
            $query->whereNotIn('id', $excluded);
        }

        $folders = $query->get();
        $labels = MediaFolder::pathLabels($folders);
        $options = [];

        foreach ($folders as $folder) {
            $options[$folder->uuid] = $labels[$folder->id] ?? $folder->name;
        }

        asort($options, SORT_NATURAL | SORT_FLAG_CASE);

        return $options;
    }
}
