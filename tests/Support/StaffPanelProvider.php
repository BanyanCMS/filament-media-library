<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Tests\Support;

use Banyan\FilamentMediaLibrary\MediaLibraryPlugin;
use Filament\Panel;
use Filament\PanelProvider;

/** A second panel on the same app, with its own page classes, for the multi-panel tests. */
final class StaffPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel->id('staff')->path('staff')->plugin(
            MediaLibraryPlugin::make()->libraryPage(CustomLibraryPage::class)->assetPage(CustomAssetPage::class),
        );
    }
}
