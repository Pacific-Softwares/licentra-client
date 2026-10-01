<?php

namespace Pacific\Licentra\Modules;

/**
 * What the host framework does for module installs. The Laravel adapter runs migrations by
 * explicit path (module migrations never join the app's own `migrate`, so a broken module can't
 * fail a product update) and clears route/config/event/view caches after every change.
 */
interface ModuleHooks
{
    /** Run the module's pending migrations. Throw with the database error on failure. */
    public function migrate(string $slug, string $migrationsPath): void;

    /** Roll back every migration in the module's folder ("uninstall and delete data"). */
    public function rollback(string $slug, string $migrationsPath): void;

    /** A module was installed, enabled, disabled, updated or removed: drop cached routes/config. */
    public function changed(): void;
}
