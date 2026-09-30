<?php

namespace Pacific\Licentra\Laravel;

use Illuminate\Console\Command;
use Pacific\Licentra\Licentra;
use Pacific\Licentra\Update\UpdateFailed;
use Pacific\Licentra\Update\Updater;

class UpdateCommand extends Command
{
    protected $signature = 'licentra:update
        {--check : Only show whether an update is available}
        {--yes : Don\'t ask for confirmation}
        {--finish : Finish an update whose files are already installed}';

    protected $description = 'Download and install the latest release of this product';

    public function handle(Updater $updater, Licentra $licentra): int
    {
        if ($this->option('finish')) {
            $updater->finishIfPending();

            return $this->report($updater->status());
        }

        $licentra->heartbeat(force: true);
        $update = $updater->available();
        if ($update === null) {
            $this->info('No update available. This is version ' . $licentra->config()->productVersion . '.');

            return self::SUCCESS;
        }

        $this->info("Version {$update['version']} is available (installed: {$licentra->config()->productVersion}).");
        if ($this->option('check')) {
            return self::SUCCESS;
        }
        if (!$this->option('yes') && !$this->confirm('Back up your database, then install it now?')) {
            return self::SUCCESS;
        }

        try {
            $updater->start($update['version']);
        } catch (UpdateFailed $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $status = $updater->runToFinish(includeFinish: false);
        $this->line('  ' . ($status['label'] ?? $status['step']));

        if ($status['step'] === 'finish') {
            // Migrate with the NEW code: run it in a fresh PHP process when the host allows it.
            $code = null;
            if (function_exists('passthru')) {
                passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(base_path('artisan')) . ' licentra:update --finish', $code);
            }
            if ($code !== 0) {
                $updater->finishIfPending();
            }
            $status = $updater->status();
        }

        return $this->report($status);
    }

    private function report(array $status): int
    {
        if (($status['step'] ?? null) === 'done') {
            $this->info("Updated to {$status['version']}.");

            return self::SUCCESS;
        }
        if (($status['step'] ?? null) === 'failed') {
            $this->error($status['error'] ?? 'Update failed.');

            return self::FAILURE;
        }
        $this->line('Update status: ' . ($status['step'] ?? 'none'));

        return self::SUCCESS;
    }
}
