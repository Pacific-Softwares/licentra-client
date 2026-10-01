<?php

namespace Pacific\Licentra\Modules\Laravel\Console;

use Pacific\Licentra\Modules\CrashGuard;
use Pacific\Licentra\Modules\ModuleException;
use Pacific\Licentra\Modules\ModuleInstaller;
use Pacific\Licentra\Modules\ModuleLicenses;
use Pacific\Licentra\Modules\Registry;

class ModuleEnableCommand extends ModuleCommand
{
    protected $signature = 'module:enable {slug}';

    protected $description = 'Switch an installed module on';

    public function handle(ModuleInstaller $installer, Registry $registry, ModuleLicenses $licenses, CrashGuard $crashes): int
    {
        return $this->change(function () use ($installer, $registry, $licenses, $crashes) {
            $slug = $this->argument('slug');
            $module = $registry->get($slug) ?? throw ModuleException::notInstalled($slug);
            $installer->enable($slug, $licenses->problem($module));
            $crashes->reset($slug);
            $this->info("{$slug} is on.");
        });
    }
}
