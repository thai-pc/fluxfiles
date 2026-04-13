<?php

declare(strict_types=1);

namespace FluxFiles\Laravel;

use FluxFiles\Laravel\Http\Controllers\FluxFilesController;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class FluxFilesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/fluxfiles.php', 'fluxfiles');

        $this->app->singleton(FluxFilesManager::class, function () {
            return new FluxFilesManager();
        });
    }

    public function boot(): void
    {
        // Artisan commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\SeedMetadataCommand::class,
            ]);
        }

        // Publish config
        $this->publishes([
            __DIR__ . '/../config/fluxfiles.php' => config_path('fluxfiles.php'),
        ], 'fluxfiles-config');

        // Load views
        $this->loadViewsFrom(__DIR__ . '/Views', 'fluxfiles');

        $this->publishes([
            __DIR__ . '/Views' => resource_path('views/vendor/fluxfiles'),
        ], 'fluxfiles-views');

        // Register Blade component
        Blade::component('fluxfiles', \FluxFiles\Laravel\Views\Components\FluxFiles::class);

        // Register routes (proxy mode only)
        if (config('fluxfiles.mode') === 'proxy') {
            $this->registerRoutes();
        }

        // Blade directives
        Blade::directive('fluxfilesToken', function (string $expression) {
            return "<?php echo app(\\FluxFiles\\Laravel\\FluxFilesManager::class)->tokenForUser({$expression}); ?>";
        });

        Blade::directive('fluxfilesEndpoint', function () {
            return "<?php echo app(\\FluxFiles\\Laravel\\FluxFilesManager::class)->endpoint(); ?>";
        });
    }

    private function registerRoutes(): void
    {
        $prefix = config('fluxfiles.route_prefix', 'api/fm');
        $middleware = config('fluxfiles.middleware', ['web', 'auth']);

        // API routes with auth middleware
        Route::prefix($prefix)
            ->middleware(array_merge($middleware, [
                Http\Middleware\FluxFilesAuth::class,
            ]))
            ->group(__DIR__ . '/../routes/fluxfiles.php');

        // Static asset routes (no auth required)
        Route::get('fluxfiles.js', [FluxFilesController::class, 'sdkJs']);
        Route::get('public/index.html', [FluxFilesController::class, 'publicIndex']);
        Route::get('assets/{file}', [FluxFilesController::class, 'asset'])
            ->where('file', '[a-zA-Z0-9._-]+');
    }
}
