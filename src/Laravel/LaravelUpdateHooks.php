<?php

namespace Pacific\Licentra\Laravel;

use Illuminate\Foundation\Http\MaintenanceModeBypassCookie;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cookie;
use Pacific\Licentra\Update\UpdateFailed;
use Pacific\Licentra\Update\UpdateHooks;
use Pacific\Licentra\Update\Updater;

/**
 * Default Laravel behaviour around an update. Extend it in your product to add steps
 * (e.g. back up the database in beforeApply) and point config('licentra.update.hooks') at it.
 */
class LaravelUpdateHooks implements UpdateHooks
{
    public function beforeApply(Updater $updater): void
    {
        $secret = bin2hex(random_bytes(16));
        Artisan::call('down', ['--secret' => $secret, '--retry' => 60]);

        // The admin running the update keeps going through maintenance mode to the finish step.
        Cookie::queue(MaintenanceModeBypassCookie::create($secret));
    }

    public function finish(Updater $updater): void
    {
        if (Artisan::call('migrate', ['--force' => true]) !== 0) {
            throw new UpdateFailed('Database migration failed: ' . trim(Artisan::output()));
        }
        Artisan::call('optimize:clear');
        try {
            Artisan::call('queue:restart'); // workers pick up the new code
        } catch (\Throwable) {
        }
        Artisan::call('up');
    }

    public function afterRollback(Updater $updater): void
    {
        Artisan::call('optimize:clear');
        Artisan::call('up');
    }
}
