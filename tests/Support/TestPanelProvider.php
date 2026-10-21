<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Tests\Support;

use Banyan\FilamentMediaLibrary\Examples\Articles\Filament\Resources\Articles\ArticleResource;
use Banyan\FilamentMediaLibrary\Examples\Articles\Filament\Resources\MediaAudit\MediaAuditResource;
use Banyan\FilamentMediaLibrary\MediaLibraryPlugin;
use Filament\Panel;
use Filament\PanelProvider;

final class TestPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel->id('admin')->path('admin')->default()->plugin(MediaLibraryPlugin::make())
            ->resources([ArticleResource::class, MediaAuditResource::class]);
    }
}
