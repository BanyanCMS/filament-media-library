<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary;

use Banyan\FilamentMediaLibrary\Admin\MediaAssetPage;
use Banyan\FilamentMediaLibrary\Admin\MediaLibraryPage;
use Filament\Contracts\Plugin;
use Filament\Facades\Filament;
use Filament\Panel;
use InvalidArgumentException;

/**
 * Adds the library and asset pages to a panel: `->plugin(MediaLibraryPlugin::make())`.
 *
 * Either page can be swapped for a subclass of your own, so a host can change
 * a query, add a header action or point at its own view without forking:
 *
 *     MediaLibraryPlugin::make()
 *         ->libraryPage(App\Filament\Pages\MediaLibrary::class)
 *         ->assetPage(App\Filament\Pages\MediaAsset::class)
 *
 * The pages link to each other through {@see libraryPageClass()} and
 * {@see assetPageClass()}, so a swapped page is the one that gets the route
 * and the links.
 *
 * The pages are added in register(), not in a `resolving(Panel::class)` hook
 * or `bootUsing()`: the first runs before the panel has an id, the second after
 * Filament has already built the panel's routes. `Panel::plugin()` calls
 * register() inline, between those two moments.
 */
final class MediaLibraryPlugin implements Plugin
{
    public const string ID = 'media-library';

    /** @var class-string<MediaLibraryPage> */
    private string $libraryPage = MediaLibraryPage::class;

    /** @var class-string<MediaAssetPage> */
    private string $assetPage = MediaAssetPage::class;

    public static function make(): self
    {
        return new self;
    }

    public function getId(): string
    {
        return self::ID;
    }

    /**
     * @param  string  $page  a subclass of MediaLibraryPage; checked at runtime, because a
     *                        config value or a typo reaches here untyped
     */
    public function libraryPage(string $page): self
    {
        if (! is_a($page, MediaLibraryPage::class, true)) {
            throw new InvalidArgumentException("[{$page}] must extend ".MediaLibraryPage::class.'.');
        }

        $this->libraryPage = $page;

        return $this;
    }

    /** @param  string  $page  a subclass of MediaAssetPage; checked at runtime */
    public function assetPage(string $page): self
    {
        if (! is_a($page, MediaAssetPage::class, true)) {
            throw new InvalidArgumentException("[{$page}] must extend ".MediaAssetPage::class.'.');
        }

        $this->assetPage = $page;

        return $this;
    }

    /** @return class-string<MediaLibraryPage> */
    public function getLibraryPage(): string
    {
        return $this->libraryPage;
    }

    /** @return class-string<MediaAssetPage> */
    public function getAssetPage(): string
    {
        return $this->assetPage;
    }

    public function register(Panel $panel): void
    {
        $panel->pages([$this->libraryPage, $this->assetPage]);
    }

    public function boot(Panel $panel): void
    {
        //
    }

    /**
     * The library page class the current panel routes, so links follow a swap.
     * Panels that add the pages without this plugin get the defaults.
     *
     * @return class-string<MediaLibraryPage>
     */
    public static function libraryPageClass(): string
    {
        return self::current()?->getLibraryPage() ?? MediaLibraryPage::class;
    }

    /** @return class-string<MediaAssetPage> */
    public static function assetPageClass(): string
    {
        return self::current()?->getAssetPage() ?? MediaAssetPage::class;
    }

    private static function current(): ?self
    {
        $panel = Filament::getCurrentPanel();

        if ($panel === null || ! $panel->hasPlugin(self::ID)) {
            return null;
        }

        $plugin = $panel->getPlugin(self::ID);

        return $plugin instanceof self ? $plugin : null;
    }
}
