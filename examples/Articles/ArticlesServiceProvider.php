<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Examples\Articles;

use Banyan\FilamentMediaLibrary\Events\MediaEvent;
use Banyan\FilamentMediaLibrary\Examples\Articles\Access\MediaPermissions;
use Banyan\FilamentMediaLibrary\Examples\Articles\Http\ShowArticle;
use Banyan\FilamentMediaLibrary\Examples\Articles\Listeners\RecordMediaAudit;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/** Wires the example into an app: tables, the editors gate, the audit listener and the public page. */
class ArticlesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Who counts as an editor. Replace with your own roles.
        $this->mergeConfigFrom(__DIR__.'/config/example-articles.php', 'example-articles');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
        $this->loadViewsFrom(__DIR__.'/resources/views', 'example-articles');

        // Permissions: map the library's manage ability onto this app's editors.
        if (config('example-articles.define_gate') === true) {
            MediaPermissions::define();
        }

        // Audit: every library change, in the library's own transaction.
        Event::listen(MediaEvent::class, RecordMediaAudit::class);

        Route::middleware('web')->get('/articles/{article}', ShowArticle::class)->name('example-articles.show')->whereNumber('article');

        // Usage tracking lives on the model: see Concerns\RecordsMediaUsage. Nothing to register here.
    }
}
