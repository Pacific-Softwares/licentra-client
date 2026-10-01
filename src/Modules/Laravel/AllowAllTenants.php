<?php

namespace Pacific\Licentra\Modules\Laravel;

use Illuminate\Http\Request;

/** Default TenantGate for single-tenant products: an enabled module is available everywhere. */
final class AllowAllTenants implements TenantGate
{
    public function allows(string $slug, Request $request): bool
    {
        return true;
    }
}
