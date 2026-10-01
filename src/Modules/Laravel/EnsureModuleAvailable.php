<?php

namespace Pacific\Licentra\Modules\Laravel;

use Closure;
use Illuminate\Http\Request;

/**
 * "licentra.module:{slug}" on every module route (ModuleServiceProvider::loadModuleRoutes):
 * 404 when the module isn't running (off, crashed, unlicensed; stale route caches included),
 * 403 when the tenant's plan doesn't include it. Default deny.
 */
class EnsureModuleAvailable
{
    public function __construct(private readonly ModuleLoader $loader, private readonly TenantGate $gate)
    {
    }

    public function handle(Request $request, Closure $next, string $slug)
    {
        abort_unless($this->loader->isLoaded($slug), 404);
        abort_unless($this->gate->allows($slug, $request), 403, 'Your plan does not include this feature.');

        return $next($request);
    }
}
