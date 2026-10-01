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
 *   - listen() / observe() / composer() / schedule() hook into the product with a safety net:
 *     if the module's callback throws, the product's own action (e.g. saving a booking) still
 *     completes; the error is logged and charged to the module, which is switched off after
 *     repeated failures. Hook into the product only through these.
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

    /** The module's registry record (manifest fields, path, status). */
    public function module(): array
    {
        return $this->module;
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

    /**
     * Routes from a file in the module, under /{slug} and named "{slug}.*", gated by
     * "licentra.module:{slug}" (404 when off, 403 for tenants without it). The URL prefix is
     * enforced, so a module can never replace one of the product's own pages.
     */
    protected function loadModuleRoutes(string $file = 'routes/web.php', array $middleware = ['web']): void
    {
        if ($this->app instanceof CachesRoutes && $this->app->routesAreCached()) {
            return;
        }
        Route::middleware([...$middleware, 'licentra.module:' . $this->slug()])
            ->prefix($this->slug())
            ->name($this->slug() . '.')
            ->group($this->modulePath($file));
    }

    /** Run module code; if it throws, log it, charge it to the module and return $fallback. */
    protected function guard(string $where, callable $callback, mixed $fallback = null): mixed
    {
        $loader = $this->app->make(ModuleLoader::class);
        if (!$loader->isLoaded($this->slug())) {
            return $fallback; // switched off earlier in this request
        }
        try {
            return $callback();
        } catch (\Throwable $e) {
            $loader->error($this->slug(), $e, $where, contained: true);

            return $fallback;
        }
    }

    /** Listen to a product (or Laravel) event. A failing listener never fails the event. */
    protected function listen(string|array $events, callable $listener): void
    {
        foreach ((array) $events as $event) {
            \Illuminate\Support\Facades\Event::listen($event, fn (...$args) => $this->guard("listener for {$event}", fn () => $listener(...$args)));
        }
    }

    /**
     * React to a model event, e.g. observe(SlotReservation::class, 'created', fn ($booking) => ...).
     * A failing callback never stops the product from saving or deleting the model.
     */
    protected function observe(string $model, string|array $events, callable $callback): void
    {
        foreach ((array) $events as $event) {
            if (!in_array($event, ['retrieved', 'creating', 'created', 'updating', 'updated', 'saving', 'saved', 'deleting', 'deleted', 'restoring', 'restored', 'forceDeleting', 'forceDeleted', 'replicating'], true)) {
                throw new \InvalidArgumentException("Unknown model event \"{$event}\".");
            }
            // Model::created(fn), Model::saving(fn), ...: public static registrars on every model.
            $model::{$event}(function ($record) use ($model, $event, $callback) {
                $this->guard(class_basename($model) . " {$event}", fn () => $callback($record));
            });
        }
    }

    /** Add data to product views. A failing composer leaves the view as it was. */
    protected function composer(string|array $views, callable $callback): void
    {
        \Illuminate\Support\Facades\View::composer($views, fn ($view) => $this->guard('view composer', fn () => $callback($view)));
    }

    /**
     * Scheduled tasks: schedule(fn (Schedule $s) => $s->call(fn () => ...)->daily()).
     * Each task's callback is guarded too.
     */
    protected function schedule(callable $define): void
    {
        $this->callAfterResolving(\Illuminate\Console\Scheduling\Schedule::class, function ($schedule) use ($define) {
            $this->guard('schedule', fn () => $define(new ModuleSchedule($schedule, $this)));
        });
    }

    /** @internal used by ModuleSchedule */
    public function guardTask(string $where, callable $task): mixed
    {
        return $this->guard($where, $task);
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
