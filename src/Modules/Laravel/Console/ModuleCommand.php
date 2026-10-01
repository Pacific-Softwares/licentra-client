<?php

namespace Pacific\Licentra\Modules\Laravel\Console;

use Illuminate\Console\Command;
use Pacific\Licentra\Exceptions\LicentraException;
use Pacific\Licentra\Modules\Laravel\LaravelModuleHooks;
use Pacific\Licentra\Modules\ModuleHooks;

/**
 * Shared by the module:* commands: report errors as one line, and since we're on the CLI,
 * rebuild the caches a module change cleared (only if the site had them cached before).
 */
abstract class ModuleCommand extends Command
{
    protected function change(callable $fn): int
    {
        $hooks = $this->laravel->make(ModuleHooks::class);
        $cached = $hooks instanceof LaravelModuleHooks && $hooks->cachesExisted();

        try {
            $fn();
        } catch (LicentraException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($cached) {
            $this->call('optimize');
        }

        return self::SUCCESS;
    }
}
