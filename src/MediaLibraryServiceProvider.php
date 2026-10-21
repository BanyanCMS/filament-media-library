<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary;

use Banyan\FilamentMediaLibrary\Contracts\UsageResolver;
use Banyan\FilamentMediaLibrary\Governance\MediaExpiryReport;
use Banyan\FilamentMediaLibrary\Governance\MediaGovernanceService;
use Banyan\FilamentMediaLibrary\Http\RenditionController;
use Banyan\FilamentMediaLibrary\Rendering\ImageProcessor;
use Banyan\FilamentMediaLibrary\Rendering\RenditionService;
use Banyan\FilamentMediaLibrary\Storage\MediaRepository;
use Banyan\FilamentMediaLibrary\Support\MediaConfig;
use Banyan\FilamentMediaLibrary\Usage\DatabaseUsageResolver;
use Banyan\FilamentMediaLibrary\Usage\UsageRecorder;
use Filament\Support\Assets\Css;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Intervention\Image\ImageManager;

/**
 * Services, routes, views and translations of the media library.
 *
 * Everything is bound with `singletonIf`/`bindIf`, so an app (or Banyan) that
 * registers its own {@see UsageResolver} first keeps it.
 */
final class MediaLibraryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/media-library.php', 'media-library');

        // In register(), not boot(): a panel plugin may read a translated
        // navigation label during another provider's register(), and Laravel
        // caches an empty answer for a namespace it does not know yet.
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'media-library');

        $this->app->singleton(ImageProcessor::class, static fn (): ImageProcessor => new ImageProcessor(
            MediaConfig::string('driver', 'gd') === 'imagick' ? ImageManager::imagick() : ImageManager::gd(),
            MediaConfig::int('renditions.quality', 82),
        ));

        $this->app->singletonIf(UsageResolver::class, static fn (): UsageResolver => new DatabaseUsageResolver(DB::connection()));
        $this->app->singleton(UsageRecorder::class, static fn (): UsageRecorder => new UsageRecorder(DB::connection()));

        $this->app->singleton(MediaRepository::class, static fn ($app): MediaRepository => new MediaRepository(
            DB::connection(),
            $app->make(FilesystemFactory::class),
            $app->make(ImageProcessor::class),
            $app->make(UsageResolver::class),
            MediaConfig::string('disk', 'public'),
            trim(MediaConfig::string('paths.originals', 'media'), '/'),
            trim(MediaConfig::string('paths.renditions', 'media/renditions'), '/'),
        ));

        $this->app->singleton(RenditionService::class, static fn ($app): RenditionService => new RenditionService(
            $app->make(MediaRepository::class),
            $app->make(ImageProcessor::class),
            MediaConfig::int('renditions.max_dimension', 4096),
        ));

        $this->app->singleton(MediaGovernanceService::class, static fn ($app): MediaGovernanceService => new MediaGovernanceService(
            DB::connection(),
            $app->make(MediaRepository::class),
            $app->make(Dispatcher::class),
        ));

        $this->app->singleton(MediaExpiryReport::class, static fn ($app): MediaExpiryReport => new MediaExpiryReport(
            DB::connection(),
            $app->make(UsageResolver::class),
        ));
    }

    public function boot(): void
    {
        if (MediaConfig::bool('migrations', true)) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'media-library');

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/media-library.php' => config_path('media-library.php')], 'media-library-config');
            $this->publishes([__DIR__.'/../database/migrations' => database_path('migrations')], 'media-library-migrations');
            $this->publishes([__DIR__.'/../resources/lang' => $this->app->langPath('vendor/media-library')], 'media-library-translations');
        }

        $this->registerRenderRoute();

        // The package name is the *second* argument: AssetManager::register()
        // overwrites each asset's own ->package() with it.
        FilamentAsset::register(
            [Css::make('media-library', __DIR__.'/../resources/css/media.css')],
            'banyancms/filament-media-library',
        );
    }

    /**
     * `signed`, not `auth`: a rendition URL sits in an <img> a public page
     * serves to anonymous readers, so it cannot need a session — but its query
     * string is an instruction to spend CPU and disk, so it has to be one this
     * application issued.
     */
    private function registerRenderRoute(): void
    {
        $this->app->make(Router::class)
            ->get(MediaConfig::string('route.path', 'media/{uuid}/render'), RenditionController::class)
            ->middleware('signed')
            ->name(MediaConfig::string('route.name', 'media-library.render'));
    }
}
