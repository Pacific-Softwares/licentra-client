<?php

namespace Pacific\Licentra\Modules\Laravel\Console;

use Pacific\Licentra\Modules\Laravel\ModuleLoader;
use Pacific\Licentra\Modules\Registry;

class ModuleListCommand extends ModuleCommand
{
    protected $signature = 'module:list';

    protected $description = 'List installed add-on modules and whether they are running';

    public function handle(Registry $registry, ModuleLoader $loader): int
    {
        $rows = [];
        foreach ($registry->all() as $slug => $m) {
            $rows[] = [$slug, $m['version'], $m['status'], $loader->isLoaded($slug) ? 'yes' : 'no', $m['reason'] ?? ($loader->skipped()[$slug] ?? '')];
        }
        foreach ($loader->loaded() as $slug => $provider) {
            if (!$registry->get($slug)) {
                $rows[] = [$slug, '-', 'dev', 'yes', 'unsigned (developer mode)'];
            }
        }
        $rows === [] ? $this->info('No modules installed.') : $this->table(['Module', 'Version', 'Status', 'Running', 'Note'], $rows);

        return self::SUCCESS;
    }
}
