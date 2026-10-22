<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Tests\Support;

use Banyan\FilamentMediaLibrary\Enums\FolderAccessMode;
use Banyan\FilamentMediaLibrary\Folders\MediaFolderService;
use Banyan\FilamentMediaLibrary\Governance\MediaGovernanceService;
use Banyan\FilamentMediaLibrary\Models\MediaAsset;
use Banyan\FilamentMediaLibrary\Models\MediaFolder;

/**
 * Builders for the folder-access tests: real users, real folders, real files.
 * Names are distinctive so a leak is found by searching the output for them.
 */
final class AccessWorld
{
    public static function user(string $name): User
    {
        return User::make($name);
    }

    public static function folder(string $name, ?MediaFolder $parent = null, ?User $owner = null): MediaFolder
    {
        return app(MediaFolderService::class)->create($parent, $name, (string) ($owner ?? self::user('Owner'))->id);
    }

    /** A folder with its access set, a password where the mode needs one. */
    public static function restricted(string $name, FolderAccessMode $mode, ?User $owner = null, ?string $password = null, ?MediaFolder $parent = null): MediaFolder
    {
        $owner ??= self::user('Owner');
        $folder = self::folder($name, $parent, $owner);

        return app(MediaFolderService::class)->setAccess($folder, $mode, $mode === FolderAccessMode::Password ? ($password ?? 'correct horse') : null, (string) $owner->id);
    }

    public static function member(MediaFolder $folder, User $user, string $role = 'view'): void
    {
        app(MediaFolderService::class)->addMember($folder, $user->id, $role, '1');
    }

    /** An image whose filename is `$name.png`, filed in `$folder` (the root when null). */
    public static function asset(string $name, ?MediaFolder $folder = null): MediaAsset
    {
        $asset = app(MediaGovernanceService::class)->upload(Images::splitPng(), $name.'.png', 'image/png', 'Alt for '.$name, '1');

        if ($folder instanceof MediaFolder) {
            app(MediaFolderService::class)->moveAssets([$asset], $folder, '1');
        }

        return $asset->refresh();
    }
}
