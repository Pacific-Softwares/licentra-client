<?php

namespace Pacific\Licentra\Modules\Laravel\Console;

use Pacific\Licentra\Modules\ModuleInstaller;

class ModuleInstallCommand extends ModuleCommand
{
    protected $signature = 'module:install {slug : e.g. slotara-whatsapp} {--code= : purchase code (paid add-ons, first install)}';

    protected $description = 'Install or update a signed add-on module from the license server';

    public function handle(ModuleInstaller $installer): int
    {
        return $this->change(function () use ($installer) {
            $installer->start($this->argument('slug'), $this->option('code'));
            $status = $installer->runToEnd();
            if ($status['step'] === 'failed') {
                $this->error($status['error'] ?? 'Install failed.');
                $installer->reset();

                throw new \Pacific\Licentra\Exceptions\LicentraException('Module not installed.');
            }
            $this->info("Installed {$status['slug']} {$status['version']}.");
            $installer->reset();
        });
    }
}
