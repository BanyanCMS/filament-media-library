<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Tests;

use Banyan\FilamentMediaLibrary\Examples\Articles\ArticlesServiceProvider;
use Banyan\FilamentMediaLibrary\MediaLibraryServiceProvider;
use Banyan\FilamentMediaLibrary\Tests\Support\TestPanelProvider;
use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\Facades\Filament;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Illuminate\Support\Facades\Storage;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * A bare Laravel app with Filament and this package — no Banyan package is
 * booted, which is what proves the library stands alone.
 *
 * Table names carry a `lib_` prefix so every query that ignored the
 * `media-library.tables` config would fail here.
 */
abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('media-lib-test');
        $this->loadLaravelMigrations();
        $this->artisan('migrate')->run();
        Filament::setCurrentPanel('admin');
    }

    protected function getPackageProviders($app): array
    {
        return [
            BladeIconsServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            SupportServiceProvider::class,
            ActionsServiceProvider::class,
            SchemasServiceProvider::class,
            FormsServiceProvider::class,
            InfolistsServiceProvider::class,
            TablesServiceProvider::class,
            NotificationsServiceProvider::class,
            WidgetsServiceProvider::class,
            FilamentServiceProvider::class,
            LivewireServiceProvider::class,
            MediaLibraryServiceProvider::class,
            // The worked example (examples/Articles): its migration, editors gate, audit listener and public route.
            ArticlesServiceProvider::class,
            TestPanelProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $config = $app['config'];

        $config->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        // Its own in-memory connection, so these tests never share a schema with a host suite.
        $config->set('database.connections.media-lib', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
        $config->set('database.default', 'media-lib');
        $config->set('filesystems.disks.media-lib-test', ['driver' => 'local', 'root' => storage_path('framework/testing/media-lib')]);
        // The example's tests define the gate themselves; every other test owns its own gate.
        $config->set('example-articles.define_gate', false);
        $config->set('media-library.disk', 'media-lib-test');
        $config->set('media-library.tables', [
            'assets' => 'lib_assets',
            'versions' => 'lib_versions',
            'tags' => 'lib_tags',
            'asset_tag' => 'lib_asset_tag',
            'usages' => 'lib_usages',
        ]);
    }
}
