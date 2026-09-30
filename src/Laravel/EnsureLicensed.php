<?php

namespace Pacific\Licentra\Laravel;

use Closure;
use Illuminate\Http\Request;
use Pacific\Licentra\Licentra;
use Symfony\Component\HttpFoundation\Response;

/**
 * Put this on your ADMIN routes only (decision: admin-only lock; the public site keeps working).
 *
 *   Route::middleware(['auth', 'licentra'])->prefix('admin')->group(...);
 *
 * Also sends the daily heartbeat after the response is delivered, so it works even when
 * the buyer never set up the scheduler cron.
 */
class EnsureLicensed
{
    public function __construct(private Licentra $licentra)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $this->licentra->observeHost($request->getHost());
        app()->terminating(fn () => $this->licentra->heartbeat());

        if ($this->licentra->isValid() || $request->routeIs(config('licentra.redirect_route'))) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => $this->licentra->state()->message(), 'license' => 'required'], 403);
        }

        return redirect()->route(config('licentra.redirect_route'));
    }
}
