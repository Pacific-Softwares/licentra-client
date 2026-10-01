<?php

namespace Pacific\Licentra\Modules\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Pacific\Licentra\Modules\ModuleException;
use Pacific\Licentra\Modules\ModuleHooks;

/**
 * Module migrations run by explicit path only, never as part of the app's own `migrate`, so a
 * product update can't be failed by a module's migration. After any module change the route,
 * config and event caches are deleted (safe from a web request; rebuilding them is left to
 * `php artisan optimize`, which the module:* commands run when the caches existed).
 */
class LaravelModuleHooks implements ModuleHooks
{
    public function __construct(private readonly Application $app)
    {
    }

    public function migrate(string $slug, string $migrationsPath): void
    {
        $code = Artisan::call('migrate', ['--path' => $migrationsPath, '--realpath' => true, '--force' => true]);
        if ($code !== 0) {
            throw ModuleException::installFailed(trim(Artisan::output()) ?: "Migrations for {$slug} failed.");
        }
    }

    public function rollback(string $slug, string $migrationsPath): void
    {
        $code = Artisan::call('migrate:reset', ['--path' => $migrationsPath, '--realpath' => true, '--force' => true]);
        if ($code !== 0) {
            throw ModuleException::installFailed(trim(Artisan::output()) ?: "Removing the data of {$slug} failed.");
        }
    }

    public function changed(): void
    {
        foreach ($this->cacheFiles() as $file) {
            if (is_file($file) && !@unlink($file)) {
                error_log("licentra: could not delete {$file} after a module change; run php artisan optimize:clear");
            }
        }
    }

    /** True if any of the caches a module change invalidates exists. */
    public function cachesExisted(): bool
    {
        return array_filter($this->cacheFiles(), 'is_file') !== [];
    }

    private function cacheFiles(): array
    {
        return [
            $this->app->getCachedRoutesPath(),
            $this->app->getCachedConfigPath(),
            $this->app->getCachedEventsPath(),
        ];
    }
}
