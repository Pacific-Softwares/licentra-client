<?php

namespace Pacific\Licentra\Laravel;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Auth\Access\Response as GateResponse;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Pacific\Licentra\Config;
use Pacific\Licentra\Licentra;
use Pacific\Licentra\Update\Updater;

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

        $this->app->singleton(Updater::class, function ($app) {
            $c = $app['config']['licentra'];
            $u = $c['update'] ?? [];

            return new Updater(
                $app->make(Licentra::class),
                $app->make($u['hooks'] ?? LaravelUpdateHooks::class),
                base_path(),
                $u['work_path'] ?? storage_path('app/licentra-update'),
                $c['release_public_key'] ?? null,
                $u['preserve'] ?? [],
                $u['merge_json'] ?? [],
                $u['merge_php'] ?? [],
            );
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

        if (!$this->app->runningInConsole()) {
            // Files of an update are in place but migrations etc. haven't run (the admin closed the tab,
            // or this is the next request). Finish now, with the new code loaded, before anything else.
            $this->app->booted(fn () => $this->finishPendingUpdate());
        }

        if ($this->app->runningInConsole()) {
            $this->commands([HeartbeatCommand::class, UpdateCommand::class]);

            $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
                // Fixed per-site minute (not random: schedule:run re-evaluates every minute) so
                // thousands of installs don't all hit the server at midnight.
                $minute = crc32((string) config('app.url')) % 1440;
                $schedule->command('licentra:heartbeat')->dailyAt(sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60));
            });
        }
    }

    /** Cheap when idle: one is_file() check. */
    public function finishPendingUpdate(): void
    {
        $work = config('licentra.update.work_path', storage_path('app/licentra-update'));
        if (!is_file($work . '/state.json')) {
            return;
        }
        try {
            $this->app->make(Updater::class)->finishIfPending();
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
