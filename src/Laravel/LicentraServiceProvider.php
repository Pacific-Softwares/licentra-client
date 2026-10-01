<?php

namespace Pacific\Licentra\Laravel;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Auth\Access\Response as GateResponse;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Pacific\Licentra\Config;
use Pacific\Licentra\Licentra;
use Pacific\Licentra\Modules\CrashGuard;
use Pacific\Licentra\Modules\Laravel\Console;
use Pacific\Licentra\Modules\Laravel\EnsureModuleAvailable;
use Pacific\Licentra\Modules\Laravel\LaravelModuleHooks;
use Pacific\Licentra\Modules\Laravel\ModuleLoader;
use Pacific\Licentra\Modules\Laravel\TenantGate;
use Pacific\Licentra\Modules\ModuleHooks;
use Pacific\Licentra\Modules\ModuleInstaller;
use Pacific\Licentra\Modules\ModuleLicenses;
use Pacific\Licentra\Modules\Registry;
use Pacific\Licentra\Update\Updater;

class LicentraServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/licentra.php', 'licentra');

        $this->app->singleton(Licentra::class, function ($app) {
            $c = $app['config']['licentra'];

            $licentra = new Licentra(new Config(
                product: (string) $c['product'],
                publicKey: (string) $c['public_key'],
                storagePath: $c['storage_path'],
                appUrl: (string) $app['config']['app.url'],
                productVersion: (string) $c['product_version'],
                serverUrl: $c['server_url'],
                frameworkVersion: $app->version(),
            ));
            if (self::modules()['enabled']) {
                // Add-on licenses ride along with the product's daily heartbeat.
                $licentra->onModules(fn (array $states) => $app->make(ModuleLicenses::class)->absorb($states, $app->make(Registry::class)));
            }

            return $licentra;
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
                $c['operation_lock'] ?? storage_path('app/licentra-operation.lock'),
                fn () => self::modules()['enabled'] && $app->make(ModuleInstaller::class)->inProgress(),
            );
        });

        $this->registerModules();
    }

    /** Module settings with defaults filled in (mergeConfigFrom only merges top-level keys). */
    public static function modules(): array
    {
        return array_replace([
            'enabled' => false,
            'path' => base_path('modules'),
            'public_path' => public_path('modules'),
            'registry' => storage_path('app/licentra-modules.php'),
            'work_path' => storage_path('app/licentra-modules-op'),
            'crash_path' => storage_path('app/licentra-crashes'),
            'audit_log' => storage_path('logs/licentra-modules.log'),
            // Fallbacks for products whose published config has a 'modules' array without these
            // keys. Products should list them in config/licentra.php so they survive config:cache.
            'dev' => (bool) env('LICENTRA_MODULES_DEV', false),
            'dev_path' => base_path('modules-dev'),
            'safe_mode' => (bool) env('LICENTRA_MODULES_SAFE', false),
            'tenant_gate' => \Pacific\Licentra\Modules\Laravel\AllowAllTenants::class,
            'back_url' => null,
        ], (array) config('licentra.modules', []));
    }

    private function registerModules(): void
    {
        // Settings are read when each service is first used, not now: config can still change
        // between register() and boot() (packages, test harnesses).
        $m = fn (string $key) => self::modules()[$key];
        $this->app->singleton(Registry::class, fn () => new Registry($m('registry')));
        $this->app->singleton(ModuleLicenses::class, fn ($app) => new ModuleLicenses($app->make(Licentra::class)));
        $this->app->singleton(CrashGuard::class, fn () => new CrashGuard($m('crash_path')));
        $this->app->singleton(ModuleHooks::class, fn ($app) => new LaravelModuleHooks($app));
        $this->app->singleton(TenantGate::class, fn ($app) => $app->make($m('tenant_gate')));
        $this->app->singleton(ModuleInstaller::class, fn ($app) => new ModuleInstaller(
            $app->make(Licentra::class),
            $app->make(Registry::class),
            $app->make(ModuleHooks::class),
            $m('path'),
            $m('public_path'),
            $m('work_path'),
            $app['config']['licentra.operation_lock'] ?? storage_path('app/licentra-operation.lock'),
            $app['config']['licentra.release_public_key'] ?? null,
            (string) $app['config']['licentra.product_version'],
            fn () => $app->make(Updater::class)->inProgress(),
            fn (string $action, string $slug, ?string $version) => $this->audit($m('audit_log'), $action, $slug, $version),
            $m('dev') ? $m('dev_path') : null,
        ));
        $this->app->singleton(ModuleLoader::class, fn ($app) => new ModuleLoader(
            $app,
            $app->make(Registry::class),
            $app->make(ModuleLicenses::class),
            $app->make(CrashGuard::class),
            $app->make(ModuleHooks::class),
            $app->make(Licentra::class),
            self::modules(),
        ));

        // Load here, not in boot(): module providers must exist before the app's own providers
        // (e.g. Filament panels asking for module plugins) register. The booting() fallback
        // covers config that was only set after this provider registered.
        if ($m('enabled')) {
            $this->app->make(ModuleLoader::class)->load();
        }
        $this->app->booting(function () {
            if (self::modules()['enabled']) {
                $this->app->make(ModuleLoader::class)->load();
            }
        });
    }

    /** Who did what to which module, one JSON line per action (storage/logs/licentra-modules.log). */
    private function audit(string $file, string $action, string $slug, ?string $version): void
    {
        $user = $this->app->bound('auth') ? $this->app['auth']->user() : null;
        $line = json_encode([
            'at' => date('c'),
            'action' => $action,
            'module' => $slug,
            'version' => $version,
            'user' => $user ? ($user->email ?? $user->getAuthIdentifier()) : ($this->app->runningInConsole() ? 'cli' : null),
            'ip' => $this->app->runningInConsole() ? null : $this->app['request']->ip(),
        ], JSON_UNESCAPED_SLASHES);
        @file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
    }

    public function boot(Router $router): void
    {
        $this->publishes([__DIR__ . '/../../config/licentra.php' => config_path('licentra.php')], 'licentra-config');
        $this->publishes([__DIR__ . '/../../resources/views' => resource_path('views/vendor/licentra')], 'licentra-views');
        $this->loadViewsFrom(__DIR__ . '/../../resources/views', 'licentra');

        $router->aliasMiddleware('licentra', EnsureLicensed::class);
        $router->aliasMiddleware('licentra.module', EnsureModuleAvailable::class);

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
            $this->commands([
                HeartbeatCommand::class,
                UpdateCommand::class,
                Console\ModuleListCommand::class,
                Console\ModuleInstallCommand::class,
                Console\ModuleEnableCommand::class,
                Console\ModuleDisableCommand::class,
                Console\ModuleUninstallCommand::class,
                Console\ModuleMigrateCommand::class,
                Console\ModuleResetCrashesCommand::class,
                Console\ModuleMakeCommand::class,
            ]);

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
