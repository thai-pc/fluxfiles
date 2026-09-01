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

        // Publish the DB storage-backend migration (FLUXFILES_STORAGE_BACKEND=db).
        $this->publishes([
            __DIR__ . '/../database/migrations' => database_path('migrations'),
        ], 'fluxfiles-migrations');

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

        // Token refresh — session-authenticated only (NOT the JWT middleware).
        // The embedded UI's onTokenRefresh hook calls this to mint a fresh JWT
        // after the old one expires, so a session-valid user recovers without a
        // full page reload. Registered BEFORE the proxy group so it is not
        // shadowed by (and does not inherit) the FluxFilesAuth JWT check.
        Route::prefix($prefix)
            ->middleware($middleware)
            ->get('token', [FluxFilesController::class, 'token']);

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

        // PUBLIC recipient routes — reached by someone with no Laravel session and
        // no JWT, authenticated only by the share/portal token in the query
        // string/body/upload (same posture as /img and /stream). Registered with
        // NO middleware — not even 'web' — same as the static asset routes above:
        // 'web' applies CSRF verification, which would 403 an external POST
        // (share/unlock, intake/upload) that carries no Laravel CSRF token, and
        // the FluxFilesAuth group above would 401 the very people these links are
        // for. See FluxFilesController::publicLink() for the full rationale — do
        // not "tighten" these into the auth group.
        Route::prefix($prefix)->get('share/info', [FluxFilesController::class, 'shareInfo']);
        Route::prefix($prefix)->post('share/unlock', [FluxFilesController::class, 'shareUnlock']);
        Route::prefix($prefix)->get('share/file', [FluxFilesController::class, 'shareFile']);
        Route::prefix($prefix)->get('intake/info', [FluxFilesController::class, 'intakeInfo']);
        Route::prefix($prefix)->post('intake/upload', [FluxFilesController::class, 'intakeUpload']);

        // Gated media stream/img — reached with a per-file signed token in the
        // query string (an <img>/<video> element can't send an Authorization
        // header), same public posture as the share/intake routes above. See
        // FluxFilesController::stream()/img() (ported from index.php's
        // handleMediaStream()/handleImageTransform()).
        Route::prefix($prefix)->get('stream', [FluxFilesController::class, 'stream']);
        Route::prefix($prefix)->get('img', [FluxFilesController::class, 'img']);

        // Recipient landing pages (share.html / intake.html) — same public
        // posture, alongside the other static asset routes above.
        Route::get('public/share.html', [FluxFilesController::class, 'sharePage']);
        Route::get('public/intake.html', [FluxFilesController::class, 'intakePage']);
    }
}
