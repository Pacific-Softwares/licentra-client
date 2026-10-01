<?php

namespace Pacific\Licentra\Modules\Laravel;

use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Base class every module's provider extends (module.json "provider"). It gives modules the
 * sanctioned way to add routes, views and translations, and it isolates boot failures:
 *
 *   - loadModuleRoutes() wraps routes in the "licentra.module:{slug}" middleware, so a module
 *     route is 404 when the module is off and 403 for a tenant whose plan doesn't include it.
 *   - boot() calls the module's bootModule() and, if it throws, switches the module off instead
 *     of failing the request.
 *   - filamentPlugins() hands Filament plugins to the host's panels (see ModuleLoader).
 *
 * Modules implement register() / bootModule() / filamentPlugins() as needed. Everything in
 * here is part of the module API (ModuleManifest::API_VERSION).
 */
abstract class ModuleServiceProvider extends ServiceProvider
{
    private array $module = [];

    /** @internal set by ModuleLoader before register() */
    public function setModule(array $module): void
    {
        $this->module = $module;
    }

    public function slug(): string
    {
        return $this->module['slug'];
    }

    /** Absolute path inside the module folder. */
    public function modulePath(string $path = ''): string
    {
        return rtrim($this->module['path'], '/') . ($path !== '' ? '/' . ltrim($path, '/') : '');
    }

    /** Filament plugins for a panel id (e.g. "admin", "tenant"). */
    public function filamentPlugins(string $panel): array
    {
        return [];
    }

    final public function boot(): void
    {
        if (!method_exists($this, 'bootModule')) {
            return;
        }
        try {
            $this->app->call([$this, 'bootModule']);
        } catch (\Throwable $e) {
            // The isolation boundary between the product and a module: log it with context and
            // switch the module off; the rest of the site keeps working.
            $this->app->make(ModuleLoader::class)->failed($this->slug(), $e, 'boot');
        }
    }

    /** Routes from a file in the module, gated by "licentra.module:{slug}". */
    protected function loadModuleRoutes(string $file = 'routes/web.php', array $middleware = ['web']): void
    {
        if ($this->app instanceof CachesRoutes && $this->app->routesAreCached()) {
            return;
        }
        Route::middleware([...$middleware, 'licentra.module:' . $this->slug()])
            ->group($this->modulePath($file));
    }

    /** Views as "{slug}::name" and translations as "{slug}::file.key". */
    protected function loadModuleResources(): void
    {
        if (is_dir($this->modulePath('resources/views'))) {
            $this->loadViewsFrom($this->modulePath('resources/views'), $this->slug());
        }
        if (is_dir($this->modulePath('lang'))) {
            $this->loadTranslationsFrom($this->modulePath('lang'), $this->slug());
            $this->loadJsonTranslationsFrom($this->modulePath('lang'));
        }
    }
}
