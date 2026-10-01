<?php

namespace Pacific\Licentra\Modules\Laravel\Console;

use Pacific\Licentra\Modules\ModuleInstaller;

class ModuleUninstallCommand extends ModuleCommand
{
    protected $signature = 'module:uninstall {slug} {--delete-data : also drop the module\'s tables (cannot be undone)}';

    protected $description = 'Remove a module\'s files (its tables are kept unless --delete-data)';

    public function handle(ModuleInstaller $installer): int
    {
        $slug = $this->argument('slug');
        $delete = (bool) $this->option('delete-data');
        if ($delete && !$this->confirm("Delete all data of {$slug}? This cannot be undone.")) {
            return self::FAILURE;
        }

        return $this->change(function () use ($installer, $slug, $delete) {
            $installer->uninstall($slug, $delete);
            $this->info("{$slug} removed" . ($delete ? ' with its data.' : '; its data is kept.'));
        });
    }
}
