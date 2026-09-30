<?php

namespace Pacific\Licentra\Update;

/**
 * What the product does around the file swap. The Laravel adapter's LaravelUpdateHooks puts
 * the site in maintenance, then migrates, clears caches and brings it back; extend it to add
 * e.g. a database backup in beforeApply().
 */
interface UpdateHooks
{
    /** Just before files are replaced: back up the database, enter maintenance mode. */
    public function beforeApply(Updater $updater): void;

    /** In a fresh request/process after the files are in place, so new code is loaded: migrate, clear caches, leave maintenance. */
    public function finish(Updater $updater): void;

    /** Files were rolled back after a failure: leave maintenance mode. */
    public function afterRollback(Updater $updater): void;
}
