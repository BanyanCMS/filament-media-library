<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary;

use Banyan\FilamentMediaLibrary\Admin\MediaAssetPage;
use Banyan\FilamentMediaLibrary\Admin\MediaLibraryPage;
use Filament\Contracts\Plugin;
use Filament\Panel;

/**
 * Adds the library and asset pages to a panel: `->plugin(MediaLibraryPlugin::make())`.
 *
 * The pages are added in register(), not in a `resolving(Panel::class)` hook
 * or `bootUsing()`: the first runs before the panel has an id, the second after
 * Filament has already built the panel's routes. `Panel::plugin()` calls
 * register() inline, between those two moments.
 */
final class MediaLibraryPlugin implements Plugin
{
    public static function make(): self
    {
        return new self;
    }

    public function getId(): string
    {
        return 'media-library';
    }

    public function register(Panel $panel): void
    {
        $panel->pages([MediaLibraryPage::class, MediaAssetPage::class]);
    }

    public function boot(Panel $panel): void
    {
        //
    }
}
