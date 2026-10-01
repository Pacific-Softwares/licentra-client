<?php

namespace Pacific\Licentra\Modules\Laravel;

use Illuminate\Http\Request;

/**
 * Multi-tenant products decide whether the current tenant may use a module (plan + per-tenant
 * override). Bind your implementation in config('licentra.modules.tenant_gate'). Single-tenant
 * products keep the default, which allows every enabled module.
 */
interface TenantGate
{
    public function allows(string $slug, Request $request): bool;
}
