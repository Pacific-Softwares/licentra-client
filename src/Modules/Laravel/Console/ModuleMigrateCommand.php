<?php

namespace Pacific\Licentra\Modules\Laravel\Console;

use Pacific\Licentra\Modules\ModuleHooks;
use Pacific\Licentra\Modules\ModuleInstaller;
use Pacific\Licentra\Modules\Registry;

class ModuleMigrateCommand extends ModuleCommand
{
    protected $signature = 'module:migrate {slug}';

    protected $description = 'Run a module\'s migrations (retry after a failure, or a developer-mode module)';

    public function handle(ModuleInstaller $installer, Registry $registry, ModuleHooks $hooks): int
    {
        $slug = $this->argument('slug');

        return $this->change(function () use ($installer, $registry, $hooks, $slug) {
            if ($registry->get($slug)) {
                $installer->retryMigrations($slug);
            } else {
                $dir = rtrim(\Pacific\Licentra\Laravel\LicentraServiceProvider::modules()['dev_path'], '/') . "/{$slug}/database/migrations";
                if (!is_dir($dir)) {
                    throw new \Pacific\Licentra\Exceptions\LicentraException("No installed module {$slug}, and no {$dir}.");
                }
                $hooks->migrate($slug, $dir);
            }
            $this->info("Migrations for {$slug} are up to date.");
        });
    }
}
