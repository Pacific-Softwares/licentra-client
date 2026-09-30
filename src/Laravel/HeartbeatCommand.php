<?php

namespace Pacific\Licentra\Laravel;

use Illuminate\Console\Command;
use Pacific\Licentra\Licentra;

class HeartbeatCommand extends Command
{
    protected $signature = 'licentra:heartbeat {--force : Send even if one was sent in the last 24h}';

    protected $description = 'Refresh the license token from the license server';

    public function handle(Licentra $licentra): int
    {
        $sent = $licentra->heartbeat((bool) $this->option('force'));
        $state = $licentra->state();

        $this->line(($sent ? 'Heartbeat sent. ' : 'Heartbeat skipped or failed. ') . 'Status: ' . $state->status->value);
        $this->line($state->message());

        return self::SUCCESS;
    }
}
