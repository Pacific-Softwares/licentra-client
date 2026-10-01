<?php

namespace Pacific\Licentra\Modules\Laravel\Console;

use Pacific\Licentra\Modules\CrashGuard;

class ModuleResetCrashesCommand extends ModuleCommand
{
    protected $signature = 'module:reset-crashes {slug}';

    protected $description = 'Let a developer-mode module that kept crashing load again';

    public function handle(CrashGuard $crashes): int
    {
        $crashes->reset($this->argument('slug'));
        $this->info('Crash count cleared.');

        return self::SUCCESS;
    }
}
