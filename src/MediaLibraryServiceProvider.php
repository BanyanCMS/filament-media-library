<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary;

use Banyan\FilamentMediaLibrary\Access\FolderAccess;
use Banyan\FilamentMediaLibrary\Contracts\PdfThumbnailer;
use Banyan\FilamentMediaLibrary\Contracts\UsageResolver;
use Banyan\FilamentMediaLibrary\Delivery\DeliveryRoutes;
use Banyan\FilamentMediaLibrary\Folders\MediaFolderService;
use Banyan\FilamentMediaLibrary\Governance\MediaExpiryReport;
use Banyan\FilamentMediaLibrary\Governance\MediaGovernanceService;
use Banyan\FilamentMediaLibrary\Http\FileController;
use Banyan\FilamentMediaLibrary\Http\RenditionController;
use Banyan\FilamentMediaLibrary\Http\RestrictedFileController;
use Banyan\FilamentMediaLibrary\Http\RestrictedRenditionController;
use Banyan\FilamentMediaLibrary\Rendering\ImageProcessor;
use Banyan\FilamentMediaLibrary\Rendering\Pdf\ImagickPdfThumbnailer;
use Banyan\FilamentMediaLibrary\Rendering\Pdf\NullPdfThumbnailer;
use Banyan\FilamentMediaLibrary\Rendering\Pdf\PdfThumbnailService;
use Banyan\FilamentMediaLibrary\Rendering\RenditionService;
use Banyan\FilamentMediaLibrary\Storage\MediaRepository;
use Banyan\FilamentMediaLibrary\Support\MediaConfig;
use Banyan\FilamentMediaLibrary\Uploads\UploadGuard;
use Banyan\FilamentMediaLibrary\Usage\DatabaseUsageResolver;
use Banyan\FilamentMediaLibrary\Usage\UsageRecorder;
use Filament\Support\Assets\Css;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Image\ImageManager;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

/**
 * Services, routes, views and translations of the media library.
 *
 * Everything is bound with `singletonIf`/`bindIf`, so an app that
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

        // Laravel's image component; a null driver defers to the app's `images.default`.
        $this->app->singleton(ImageProcessor::class, static fn ($app): ImageProcessor => new ImageProcessor(
            $app->make(ImageManager::class),
            MediaConfig::int('renditions.quality', 82),
            MediaConfig::nullableString('driver'),
        ));

        $this->app->singletonIf(UsageResolver::class, static fn (): UsageResolver => new DatabaseUsageResolver(DB::connection()));
        $this->app->singleton(UsageRecorder::class, static fn (): UsageRecorder => new UsageRecorder(DB::connection()));

        $this->app->singleton(UploadGuard::class, static fn ($app): UploadGuard => new UploadGuard(
            $app->make(ImageProcessor::class),
            $app->make(Dispatcher::class),
            $app,
        ));

        $this->app->singleton(MediaRepository::class, static fn ($app): MediaRepository => new MediaRepository(
            DB::connection(),
            $app->make(FilesystemFactory::class),
            $app->make(UploadGuard::class),
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

        // First-page PDF thumbnails need Imagick with a PDF delegate; without it, the null object keeps the icon.
        $this->app->singletonIf(PdfThumbnailer::class, static fn (): PdfThumbnailer => ImagickPdfThumbnailer::supported()
            ? new ImagickPdfThumbnailer
            : new NullPdfThumbnailer);

        $this->app->singleton(PdfThumbnailService::class, static fn ($app): PdfThumbnailService => new PdfThumbnailService(
            $app->make(MediaRepository::class),
            $app->make(PdfThumbnailer::class),
            $app->make(RenditionService::class),
        ));

        $this->app->singleton(MediaGovernanceService::class, static fn ($app): MediaGovernanceService => new MediaGovernanceService(
            DB::connection(),
            $app->make(MediaRepository::class),
            $app->make(Dispatcher::class),
        ));

        $this->app->singleton(MediaFolderService::class, static fn ($app): MediaFolderService => new MediaFolderService(
            DB::connection(),
            $app->make(Dispatcher::class),
        ));

        // Scoped, not singleton: it caches the folder table, and that copy must not outlive a request
        // (Octane, queue workers). The folder model flushes it on every save and delete,
        // and MediaFolderService flushes it after a member change.
        $this->app->scoped(FolderAccess::class, static fn ($app): FolderAccess => new FolderAccess($app->make(Dispatcher::class)));

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
        // <x-media-library::file :asset="$asset" />
        Blade::anonymousComponentPath(__DIR__.'/../resources/views/components', 'media-library');

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/media-library.php' => config_path('media-library.php')], 'media-library-config');
            $this->publishes([__DIR__.'/../database/migrations' => database_path('migrations')], 'media-library-migrations');
            $this->publishes([__DIR__.'/../resources/lang' => $this->app->langPath('vendor/media-library')], 'media-library-translations');
            // A published view in resources/views/vendor/media-library wins over the package's own.
            $this->publishes([__DIR__.'/../resources/views' => resource_path('views/vendor/media-library')], 'media-library-views');
        }

        $this->registerRenderRoute();
        $this->registerFileRoute();
        $this->registerRestrictedRoutes();

        // The package name is the *second* argument: AssetManager::register()
        // overwrites each asset's own ->package() with it.
        FilamentAsset::register(
            [Css::make('media-library', __DIR__.'/../resources/css/media.css')],
            'banyancms/filament-media-library',
        );
    }

    /**
     * `signed`, not `auth`: a rendition URL sits in an <img> a public page
     * serves to anonymous readers, so it cannot need a session. Its query
     * string is an instruction to spend CPU and disk, so it has to be one this
     * application issued.
     */
    private function registerRenderRoute(): void
    {
        $this->app->make(Router::class)
            ->get(DeliveryRoutes::renderPath(), RenditionController::class)
            ->middleware('signed')
            ->name(DeliveryRoutes::renderName());
    }

    /**
     * `signed` for the same reason as the rendition route: the URL is embedded
     * in public pages, so it cannot need a session, but it must be one this
     * application issued (and it carries the version it was issued for).
     */
    private function registerFileRoute(): void
    {
        $this->app->make(Router::class)
            ->get(DeliveryRoutes::filePath(), FileController::class)
            ->middleware('signed')
            ->name(DeliveryRoutes::fileName());
    }

    /**
     * The routes restricted files are served from. They are
     * `signed` like the public ones, but the addresses are temporary, and they
     * run inside the host's session middleware (`web` by default) because the
     * controllers ask who is signed in. They are separate routes so that the
     * public ones stay session-free: a Set-Cookie on every public image would
     * make them uncacheable by a CDN.
     */
    private function registerRestrictedRoutes(): void
    {
        $router = $this->app->make(Router::class);
        $configured = MediaConfig::strings('delivery.restricted_middleware');
        $middleware = [...($configured === [] ? ['web'] : $configured), 'signed'];

        $router
            ->get(DeliveryRoutes::restrictedFilePath(), RestrictedFileController::class)
            ->middleware($middleware)
            ->name(DeliveryRoutes::restrictedFileName());

        $router
            ->get(DeliveryRoutes::restrictedRenderPath(), RestrictedRenditionController::class)
            ->middleware($middleware)
            ->name(DeliveryRoutes::restrictedRenderName());
    }
}
