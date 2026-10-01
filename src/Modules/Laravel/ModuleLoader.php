<?php

namespace Pacific\Licentra\Modules\Laravel;

use Composer\Autoload\ClassLoader;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Pacific\Licentra\Licentra;
use Pacific\Licentra\Modules\CrashGuard;
use Pacific\Licentra\Modules\ModuleException;
use Pacific\Licentra\Modules\ModuleHooks;
use Pacific\Licentra\Modules\ModuleLicenses;
use Pacific\Licentra\Modules\ModuleManifest;
use Pacific\Licentra\Modules\Registry;

/**
 * Loads modules at the start of every request, from LicentraServiceProvider::register() (so
 * before the app's own providers, and Filament panels can ask for module plugins):
 *
 *   safe mode? ─yes─▶ load nothing
 *   enabled modules (registry) + modules-dev/* when developer mode is on
 *     └ each: compatible with this product version? licensed? not crash-tripped?
 *         no ─▶ skipped (reason shown on the Modules page; nothing written)
 *         yes ─▶ PSR-4 prefix → provider (must extend ModuleServiceProvider) → app->register()
 *                  throws ─▶ failed(): logged with context, module switched off
 *   CrashGuard watches the loaded modules' folders for fatals for the rest of the request.
 */
final class ModuleLoader
{
    /** @var array<string, ModuleServiceProvider> */
    private array $loaded = [];

    /** @var array<string, string> slug => why it didn't load this request */
    private array $skipped = [];

    private bool $ran = false;

    public function __construct(
        private readonly Application $app,
        private readonly Registry $registry,
        private readonly ModuleLicenses $licenses,
        private readonly CrashGuard $crashes,
        private readonly ModuleHooks $hooks,
        private readonly Licentra $licentra,
        private readonly array $config,
    ) {
    }

    /** Load enabled modules. Runs once per request; later calls do nothing. */
    public function load(): void
    {
        if ($this->ran) {
            return;
        }
        $this->ran = true;
        if (!empty($this->config['safe_mode'])) {
            return;
        }

        $modules = array_filter($this->registry->all(), fn (array $m) => ($m['status'] ?? null) === Registry::ENABLED);
        if (!empty($this->config['dev'])) {
            $modules += $this->devModules(); // an installed module wins over a dev copy of the same slug
        }

        $paths = [];
        foreach ($modules as $slug => $module) {
            $reason = $this->blocker($module);
            if ($reason !== null) {
                $this->skipped[$slug] = $reason;
            } else {
                $paths[$slug] = $module['path'];
            }
        }
        if ($paths === []) {
            return;
        }
        $this->crashes->watch($paths, fn (string $slug, string $message) => $this->switchOff($slug, 'Crashed ' . CrashGuard::LIMIT . " times in a few minutes: {$message}"));

        $autoload = self::composerLoader();
        foreach ($paths as $slug => $path) {
            $module = $modules[$slug];
            $autoload?->addPsr4($module['namespace'], $path . '/src');
            try {
                $class = $module['provider'];
                if (!class_exists($class) || !is_subclass_of($class, ModuleServiceProvider::class)) {
                    throw ModuleException::manifestInvalid("{$class} must exist and extend " . ModuleServiceProvider::class . '.');
                }
                $provider = new $class($this->app);
                $provider->setModule($module);
                $this->loaded[$slug] = $provider;
                $this->app->register($provider);
            } catch (\Throwable $e) {
                $this->failed($slug, $e, 'register');
            }
        }
    }

    public function isLoaded(string $slug): bool
    {
        return isset($this->loaded[$slug]);
    }

    /** @return array<string, ModuleServiceProvider> */
    public function loaded(): array
    {
        return $this->loaded;
    }

    /** @return array<string, string> slug => reason */
    public function skipped(): array
    {
        return $this->skipped;
    }

    /** Filament plugins from every running module, for one panel id: ->plugins([...$loader->filamentPlugins('admin')]). */
    public function filamentPlugins(string $panel): array
    {
        $plugins = [];
        foreach ($this->loaded as $slug => $provider) {
            try {
                array_push($plugins, ...$provider->filamentPlugins($panel));
            } catch (\Throwable $e) {
                $this->failed($slug, $e, 'filament');
            }
        }

        return $plugins;
    }

    /** A module threw while starting: log it with context and switch it off. */
    public function failed(string $slug, \Throwable $e, string $phase): void
    {
        unset($this->loaded[$slug]);
        $this->skipped[$slug] = $e->getMessage();
        Log::error('licentra.module.failed', [
            'module' => $slug,
            'phase' => $phase,
            'exception' => $e::class,
            'message' => $e->getMessage(),
            'at' => $e->getFile() . ':' . $e->getLine(),
        ]);
        $this->switchOff($slug, "Crashed while starting ({$phase}): {$e->getMessage()}");
    }

    private function switchOff(string $slug, string $reason): void
    {
        try {
            if ($this->registry->get($slug) && empty($this->registry->get($slug)['dev'])) {
                $this->registry->setStatus($slug, Registry::DISABLED, $reason);
                $this->hooks->changed();
            } else {
                $this->crashes->trip($slug); // developer-mode module: the crash file keeps it off
            }
            Log::warning('licentra.module.auto_disabled', ['module' => $slug, 'reason' => $reason]);
        } catch (\Pacific\Licentra\Exceptions\LicentraException $e) {
            error_log("licentra: could not switch off module {$slug}: {$e->getMessage()}");
        }
    }

    private function blocker(array $module): ?string
    {
        try {
            ModuleManifest::fromRegistry($module)->assertCompatible($this->licentra->config()->product, $this->licentra->config()->productVersion);
        } catch (ModuleException $e) {
            return $e->getMessage();
        }
        if (!empty($module['dev']) && $this->crashes->tripped($module['slug'])) {
            return 'Crashed repeatedly. Fix it, then run php artisan module:reset-crashes ' . $module['slug'] . '.';
        }

        return $this->licenses->problem($module);
    }

    /** @return array<string, array> */
    private function devModules(): array
    {
        $modules = [];
        foreach (glob(rtrim($this->config['dev_path'], '/') . '/*/' . ModuleManifest::FILE) ?: [] as $file) {
            $dir = dirname($file);
            try {
                $manifest = ModuleManifest::fromFile($file);
                $modules[$manifest->slug] = $manifest->toArray() + ['path' => $dir, 'dev' => true, 'status' => Registry::ENABLED];
            } catch (ModuleException $e) {
                $this->skipped[basename($dir)] = $e->getMessage();
            }
        }

        return $modules;
    }

    private static function composerLoader(): ?ClassLoader
    {
        foreach (spl_autoload_functions() ?: [] as $fn) {
            if (is_array($fn) && $fn[0] instanceof ClassLoader) {
                return $fn[0];
            }
        }

        return null;
    }
}
