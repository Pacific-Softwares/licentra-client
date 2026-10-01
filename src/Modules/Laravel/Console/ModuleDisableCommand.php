<?php

namespace Pacific\Licentra\Modules\Laravel\Console;

use Pacific\Licentra\Modules\ModuleInstaller;
use Pacific\Licentra\Modules\Registry;

class ModuleDisableCommand extends ModuleCommand
{
    protected $signature = 'module:disable {slug? : omit with --all} {--all : switch every module off (when a module broke the site)}';

    protected $description = 'Switch installed modules off (their data is kept)';

    public function handle(ModuleInstaller $installer, Registry $registry): int
    {
        return $this->change(function () use ($installer, $registry) {
            $slugs = $this->option('all') ? array_keys($registry->all()) : [(string) $this->argument('slug')];
            foreach (array_filter($slugs) as $slug) {
                $installer->disable($slug, 'Switched off from the command line.');
                $this->info("{$slug} is off.");
            }
        });
    }
}
