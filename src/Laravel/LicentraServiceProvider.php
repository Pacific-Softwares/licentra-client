<?php

namespace Ishalabs\Licentra\Laravel;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Auth\Access\Response as GateResponse;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Ishalabs\Licentra\Config;
use Ishalabs\Licentra\Licentra;

class LicentraServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/licentra.php', 'licentra');

        $this->app->singleton(Licentra::class, function ($app) {
            $c = $app['config']['licentra'];

            return new Licentra(new Config(
                product: (string) $c['product'],
                publicKey: (string) $c['public_key'],
                storagePath: $c['storage_path'],
                appUrl: (string) $app['config']['app.url'],
                productVersion: (string) $c['product_version'],
                serverUrl: $c['server_url'],
                frameworkVersion: $app->version(),
            ));
        });
    }

    public function boot(Router $router): void
    {
        $this->publishes([__DIR__ . '/../../config/licentra.php' => config_path('licentra.php')], 'licentra-config');
        $this->publishes([__DIR__ . '/../../resources/views' => resource_path('views/vendor/licentra')], 'licentra-views');
        $this->loadViewsFrom(__DIR__ . '/../../resources/views', 'licentra');

        $router->aliasMiddleware('licentra', EnsureLicensed::class);

        // Deny-by-default fallback, registered after the app's own providers so theirs wins.
        $this->app->booted(function () {
            $gate = config('licentra.gate');
            if (!Gate::has($gate)) {
                Gate::define($gate, fn () => GateResponse::deny(
                    "Define the \"{$gate}\" gate in your AppServiceProvider to allow admins to manage the license."
                ));
            }
        });

        if (config('licentra.routes')) {
            $this->loadRoutesFrom(__DIR__ . '/../../routes/licentra.php');
        }

        if ($this->app->runningInConsole()) {
            $this->commands([HeartbeatCommand::class]);

            $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
                // Fixed per-site minute (not random: schedule:run re-evaluates every minute) so
                // thousands of installs don't all hit the server at midnight.
                $minute = crc32((string) config('app.url')) % 1440;
                $schedule->command('licentra:heartbeat')->dailyAt(sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60));
            });
        }
    }
}
